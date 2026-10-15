-- Fictitious data for the Data API Builder integration tests.

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL
);

CREATE TABLE tasks (
    id SERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    done BOOLEAN NOT NULL DEFAULT FALSE,
    due_date TIMESTAMPTZ NULL,
    user_id INT NULL REFERENCES users (id)
);

-- A text identifier, which Data API Builder cannot count on.
CREATE TABLE labels (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    position INT NOT NULL,
    weight FLOAT NULL
);

INSERT INTO users (id, name) VALUES
    (1, 'User A'),
    (2, 'User B');

INSERT INTO tasks (id, title, done, due_date, user_id) VALUES
    (1, 'Write the docs', FALSE, '2026-01-15T10:00:00Z', 1),
    (2, 'Review the tests', TRUE, NULL, 1),
    (3, 'Release the bundle', FALSE, '2026-02-01T09:00:00Z', 2),
    (4, 'Plan the next release', FALSE, NULL, NULL);

INSERT INTO labels (code, name, position, weight) VALUES
    ('bug', 'Bug', 1, 0.5),
    ('feature', 'Feature', 2, NULL),
    ('docs', 'Docs', 3, NULL);
