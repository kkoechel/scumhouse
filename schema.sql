-- Scumhouse schema.
--
-- Read PROTOCOL.md before changing anything in here. Several tables exist in an
-- awkward shape ON PURPOSE, because the whole point of this game is that the
-- operator cannot join account -> role. The two invariants that matter:
--
--   1. anon_slots, anon_posts, anon_actions and sealed_cards NEVER carry a
--      user_id, and no table anywhere joins a slot_index to a user_id until
--      that player dies and voluntarily flips (see `flips`).
--   2. game_credentials records THAT a player drew their one blind-signed
--      credential, never WHAT it was -- storing the blinded value would let
--      anyone with the RSA private exponent re-derive the link later.
--
-- Access control lives in allowed_emails / access_requests below when
-- config allowlist.mode is 'local' (the default). Set it to 'portal' instead to
-- defer to a shared table in another database on the same server -- those two
-- tables are then simply unused. See inc/auth.php's is_allowed_email().

-- Who may sign in at all. Sign-in is invite-only by design: this is a game you
-- play with people you know, and an open sign-up would let a stranger take a seat
-- at somebody's table.
CREATE TABLE IF NOT EXISTS allowed_emails (
    email VARCHAR(255) NOT NULL PRIMARY KEY,
    note VARCHAR(255) NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_at DATETIME NULL,
    INDEX (handled_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS magic_link_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    INDEX (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status ENUM('lobby','registration','dealing','active','finished','abandoned')
        NOT NULL DEFAULT 'lobby',
    num_seats TINYINT NOT NULL DEFAULT 7,
    -- Public role composition for num_seats, e.g. {"MAFIA":2,"COP":1,"DOCTOR":1,"TOWN":3}.
    -- Published in the lobby BEFORE anyone commits; several integrity checks in
    -- PROTOCOL.md sec 9 depend on it being public and fixed at creation.
    setup_json TEXT NOT NULL,
    -- 'day'/'night' alternate once status='active'. phase_no counts from 1 and
    -- increments on each night, so day 3 and night 3 share phase_no=3.
    phase ENUM('day','night') NULL,
    phase_no SMALLINT NOT NULL DEFAULT 0,
    phase_ends_at DATETIME NULL,
    -- Hours per day phase / night phase, chosen at creation. Forum pacing.
    day_hours SMALLINT NOT NULL DEFAULT 48,
    night_hours SMALLINT NOT NULL DEFAULT 24,
    -- The fixed release point for envelope-key answers, set when each night
    -- begins (PROTOCOL.md sec 5.3). Retrieval tokens are accepted up to this
    -- moment and every answer becomes readable at it, together, so that no
    -- answer's availability is tied to when its question was asked.
    key_release_at DATETIME NULL,
    -- Per-game RSA-2048 credential key (PROTOCOL.md sec 3). The private exponent
    -- is the ONLY server secret whose leak would let someone forge extra anon
    -- identities -- it can never de-anonymise an already-issued one, because
    -- blinded values are not stored.
    cred_n TEXT NULL,
    cred_e VARCHAR(16) NULL,
    cred_d TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    winner_faction ENUM('TOWN','MAFIA') NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    user_id INT NOT NULL,
    seat_order TINYINT NOT NULL,
    is_alive TINYINT(1) NOT NULL DEFAULT 1,
    -- Public facts only: WHEN and HOW they died, never what they were. The role
    -- only ever lands in `flips`, published by the dead player's own client.
    died_phase_no SMALLINT NULL,
    died_cause ENUM('lynch','kill') NULL,
    UNIQUE (game_id, user_id),
    UNIQUE (game_id, seat_order),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per player once they have spent their blind-signature credential.
-- Records ONLY that it happened. See the header note -- never add a column here
-- holding the blinded value or the resulting signature.
CREATE TABLE IF NOT EXISTS game_credentials (
    game_id INT NOT NULL,
    user_id INT NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_id, user_id),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The anonymous identities. NO user_id, ever. slot_index is assigned after
-- registration closes, by sorting on sha256(anon_pub) -- a canonical order that
-- neither the server nor any player can steer.
CREATE TABLE IF NOT EXISTS anon_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    slot_index TINYINT NULL,
    idk_pub VARCHAR(255) NOT NULL,
    sigk_pub VARCHAR(255) NOT NULL,
    pub_hash CHAR(64) NOT NULL,
    -- The unblinded RSA-FDH signature the client presented. Kept so the slot set
    -- stays auditable after the fact; it is a signature over the PUBLIC keys and
    -- reveals nothing about who presented it.
    credential_sig TEXT NOT NULL,
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (game_id, pub_hash),
    UNIQUE (game_id, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The server's own knowledge of the deal: slot -> role. This IS known to the
-- operator and that is fine and necessary (PROTOCOL.md sec 5) -- it is what lets
-- the server validate night actions with no human moderator. It is useless
-- without slot -> account, which nothing here provides.
CREATE TABLE IF NOT EXISTS slot_roles (
    game_id INT NOT NULL,
    slot_index TINYINT NOT NULL,
    role ENUM('MAFIA','COP','DOCTOR','VIGILANTE','ROLEBLOCKER','TRACKER','WATCHER','TOWN') NOT NULL,
    PRIMARY KEY (game_id, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ECIES blob per slot, decryptable only by that slot's idk private key.
CREATE TABLE IF NOT EXISTS sealed_cards (
    game_id INT NOT NULL,
    slot_index TINYINT NOT NULL,
    ciphertext TEXT NOT NULL,
    PRIMARY KEY (game_id, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The anonymous broadcast channel. Every living player posts exactly one
-- fixed-size entry per phase (cover traffic); only the intended readers can
-- decrypt any given one. Rows stay hidden until visible_at so that submission
-- ORDER carries no information either.
CREATE TABLE IF NOT EXISTS anon_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    phase_no SMALLINT NOT NULL,
    phase ENUM('day','night') NOT NULL,
    slot_index TINYINT NOT NULL,
    ciphertext TEXT NOT NULL,
    sig TEXT NOT NULL,
    visible_at DATETIME NOT NULL,
    INDEX (game_id, phase_no, phase),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS anon_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    night_no SMALLINT NOT NULL,
    slot_index TINYINT NOT NULL,
    action ENUM('kill','vigkill','protect','block') NOT NULL,
    -- NULL for 'block', which names a SLOT rather than an account: the
    -- roleblocker learned that slot from an envelope the server cannot open, and
    -- telling the server the account would hand it the very link the two-lock
    -- envelope exists to withhold (PROTOCOL.md sec 5.2).
    target_user_id INT NULL,
    target_slot TINYINT NULL,
    sig TEXT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Last valid submission per slot per night wins; earlier ones stay for audit.
    superseded TINYINT(1) NOT NULL DEFAULT 0,
    INDEX (game_id, night_no, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (target_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The public day thread. Ordinary attributed forum posts -- this is the game
-- itself and is deliberately not encrypted.
CREATE TABLE IF NOT EXISTS day_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    phase_no SMALLINT NOT NULL,
    user_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (game_id, phase_no, created_at),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public, attributed, and revocable until the day deadline.
CREATE TABLE IF NOT EXISTS votes (
    game_id INT NOT NULL,
    phase_no SMALLINT NOT NULL,
    voter_user_id INT NOT NULL,
    -- NULL = an explicit "no lynch" vote, which is not the same as not voting.
    target_user_id INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (game_id, phase_no, voter_user_id),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (voter_user_id) REFERENCES users(id),
    FOREIGN KEY (target_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The ONLY table that ever joins a user_id to a slot_index. Written by the dead
-- player's own client, signed by that slot's key so nobody can flip a role they
-- do not hold. Until a row lands here, the operator's slot_roles table is inert.
CREATE TABLE IF NOT EXISTS flips (
    game_id INT NOT NULL,
    slot_index TINYINT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('MAFIA','COP','DOCTOR','VIGILANTE','ROLEBLOCKER','TRACKER','WATCHER','TOWN') NOT NULL,
    sig TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_id, slot_index),
    UNIQUE (game_id, user_id),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Forced flips (PROTOCOL.md sec 9).
--
-- A dead player who tampers with their client can refuse to open their card and
-- stall the game for everyone. These three tables let the survivors open it
-- without them -- and, just as importantly, make refusing to help VISIBLE.
-- ---------------------------------------------------------------------------

-- The player's own flip, sealed under a key they then split. Published while
-- logged in, so the account label is server-attested exactly as the forward
-- envelope's is.
CREATE TABLE IF NOT EXISTS flip_blobs (
    game_id INT NOT NULL,
    user_id INT NOT NULL,
    ciphertext TEXT NOT NULL,
    PRIMARY KEY (game_id, user_id),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One Shamir share of that key per slot, each sealed to that slot's public key.
-- Threshold is num_seats-1, so every OTHER player must take part: no smaller
-- coalition can open a living player's card, and the ones who could already know
-- everything by elimination anyway (PROTOCOL.md sec 1).
CREATE TABLE IF NOT EXISTS flip_shares (
    game_id INT NOT NULL,
    subject_user_id INT NOT NULL,
    holder_slot TINYINT NOT NULL,
    ciphertext TEXT NOT NULL,
    PRIMARY KEY (game_id, subject_user_id, holder_slot),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (subject_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shares opened in the clear once a dead player has failed to flip and the clock
-- has actually stalled. Posted ANONYMOUSLY and signed by the holding slot -- a
-- logged-in reveal would hand the server account->slot for every helper.
--
-- These rows are public on purpose. Whoever withholds is visible by their
-- absence, so a dead mafia's partners must choose between letting the flip
-- through and identifying themselves.
CREATE TABLE IF NOT EXISTS flip_share_reveals (
    game_id INT NOT NULL,
    subject_user_id INT NOT NULL,
    holder_slot TINYINT NOT NULL,
    share VARCHAR(128) NOT NULL,
    revealed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_id, subject_user_id, holder_slot),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (subject_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opt-in, password-wrapped backup of a player's two private keys, for the
-- "I cleared my browser mid-game" case. PBKDF2-SHA256 600k, wrapped client-side;
-- the password never reaches the server.
--
-- WEAKEST LINK IN THE WHOLE DESIGN: this row DOES tie a user_id to their (still
-- encrypted) anon keys. A weak password plus an offline attack de-anonymises that
-- player. The UI says so, enforces a passphrase, and defaults this OFF.
CREATE TABLE IF NOT EXISTS key_backups (
    game_id INT NOT NULL,
    user_id INT NOT NULL,
    wrapped_blob TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_id, user_id),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Investigative roles (PROTOCOL.md sec 5.2). Three tables, and the split between
-- them IS the security argument -- read that section before touching any of them.
-- ---------------------------------------------------------------------------

-- The OUTER lock. One row per (player, investigator slot): the player's account
-- and slot, signed by that slot's key, sealed to the investigator's public key.
-- The server stores these and can never open one -- it holds no slot's private
-- key. Labelled by user_id on purpose: the label is public, the contents are not.
CREATE TABLE IF NOT EXISTS role_envelopes (
    game_id INT NOT NULL,
    user_id INT NOT NULL,
    investigator_slot TINYINT NOT NULL,
    ciphertext TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_id, user_id, investigator_slot),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The INNER lock. Generated by the player, handed to the server, and useless to
-- it: without the investigator's private key there is nothing to apply it to.
-- Withholding these is the entire rate limit on investigation.
CREATE TABLE IF NOT EXISTS envelope_keys (
    game_id INT NOT NULL,
    user_id INT NOT NULL,
    inner_key VARCHAR(64) NOT NULL,
    PRIMARY KEY (game_id, user_id),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One blind-signed retrieval token per slot per night. As with game_credentials,
-- this records only THAT a token was drawn -- storing the blinded value would let
-- anyone with cred_d re-link the redemption to the slot that requested it, which
-- is exactly what the blinding is for.
CREATE TABLE IF NOT EXISTS retrieval_tokens (
    game_id INT NOT NULL,
    night_no SMALLINT NOT NULL,
    slot_index TINYINT NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_id, night_no, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Spent tokens, so one cannot be redeemed twice. Deliberately does NOT record
-- which slot redeemed it -- that is unknowable here and must stay that way.
-- target_user_id is recorded because the server has to look the key up; it is one
-- of N accounts named this night, N-1 of them cover traffic.
CREATE TABLE IF NOT EXISTS spent_tokens (
    game_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    night_no SMALLINT NOT NULL,
    target_user_id INT NOT NULL,
    -- One-use key the answer gets sealed to at the release point. Supplied with
    -- the redemption; fresh every time, so two answers to the same investigator
    -- cannot be linked by the key they were sealed to.
    ephemeral_pub VARCHAR(255) NOT NULL,
    -- DELIBERATELY NO redeemed_at. A timestamp here would order the night's
    -- redemptions, and the investigator is the one who deliberates while everyone
    -- else spends on autopilot -- so the ordering IS the tell. What the table
    -- holds is an unordered set per night, which is all resolution needs.
    PRIMARY KEY (game_id, token_hash),
    INDEX (game_id, night_no),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (target_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- The REVERSE direction, for the watcher (PROTOCOL.md sec 5.4).
--
-- A watcher's answer is a set of SLOTS -- the server knows which slots targeted
-- a given account, because it holds every action. Turning those into names runs
-- the lookup backwards, and the forward envelope cannot do it: it is indexed by
-- account, and the server cannot pick out "the envelope whose slot is 3" without
-- already knowing the mapping.
--
-- So there is a second envelope, indexed by SLOT. It has to be published
-- anonymously (a logged-in POST of a slot-labelled row would be the leak itself),
-- which costs the server-attested account label the forward envelope enjoys. That
-- is what account_keys below is for.
-- ---------------------------------------------------------------------------

-- An account-bound signing key, published while logged in, so the server attests
-- the binding. Carries no slot and reveals nothing on its own.
CREATE TABLE IF NOT EXISTS account_keys (
    game_id INT NOT NULL,
    user_id INT NOT NULL,
    sigk_pub VARCHAR(255) NOT NULL,
    PRIMARY KEY (game_id, user_id),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Slot -> account, sealed to the watcher and locked with an inner key, posted
-- anonymously and signed by the slot it names. The payload carries TWO signatures
-- -- one by the slot key, one by that account's key -- so the watcher can verify
-- the same person holds both. See PROTOCOL.md sec 5.4 for the one attack this
-- still admits and why it barely matters.
CREATE TABLE IF NOT EXISTS reverse_envelopes (
    game_id INT NOT NULL,
    slot_index TINYINT NOT NULL,
    ciphertext TEXT NOT NULL,
    -- The inner lock, handed to the server exactly as in the forward direction and
    -- safe for the same reason: it cannot reach past the watcher's seal.
    inner_key VARCHAR(64) NOT NULL,
    PRIMARY KEY (game_id, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watcher_queries (
    game_id INT NOT NULL,
    night_no SMALLINT NOT NULL,
    slot_index TINYINT NOT NULL,
    target_user_id INT NOT NULL,
    ephemeral_pub VARCHAR(255) NOT NULL,
    sig TEXT NOT NULL,
    PRIMARY KEY (game_id, night_no, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (target_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watcher_reports (
    game_id INT NOT NULL,
    night_no SMALLINT NOT NULL,
    slot_index TINYINT NOT NULL,
    ciphertext TEXT NOT NULL,
    PRIMARY KEY (game_id, night_no, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A tracker's question: "what did this SLOT target tonight?" Naming a slot is
-- safe -- the server knows every slot's action already and still cannot say whose
-- slot it is. The tracker learned that slot from an envelope, through an anonymous
-- redemption the server cannot connect to this query.
CREATE TABLE IF NOT EXISTS tracker_queries (
    game_id INT NOT NULL,
    night_no SMALLINT NOT NULL,
    slot_index TINYINT NOT NULL,
    target_slot TINYINT NOT NULL,
    ephemeral_pub VARCHAR(255) NOT NULL,
    sig TEXT NOT NULL,
    PRIMARY KEY (game_id, night_no, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Answered at dawn rather than mid-night, because the answer does not exist until
-- the night's actions are final. That is the natural tracker timing anyway.
CREATE TABLE IF NOT EXISTS tracker_reports (
    game_id INT NOT NULL,
    night_no SMALLINT NOT NULL,
    slot_index TINYINT NOT NULL,
    ciphertext TEXT NOT NULL,
    PRIMARY KEY (game_id, night_no, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The slot -> role table, sealed to the cop's public key at deal time. Inert on
-- its own: it names slots, not people.
CREATE TABLE IF NOT EXISTS sealed_role_table (
    game_id INT NOT NULL,
    slot_index TINYINT NOT NULL,
    ciphertext TEXT NOT NULL,
    PRIMARY KEY (game_id, slot_index),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Standard bug-report table -- default inclusion for every game in this repo.
-- NOTE: the snapshot is captured SERVER-side only. The client must never attach
-- decrypted card state to a bug report, or a bug report becomes a role leak.
CREATE TABLE IF NOT EXISTS bug_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    reported_by_user_id INT NOT NULL,
    description TEXT NOT NULL,
    state_snapshot_json LONGTEXT NOT NULL,
    image_paths_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (reported_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
