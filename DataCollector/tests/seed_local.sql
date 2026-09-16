-- Local TRI fixture: both schemas in one database (no cross-DB JOIN).
-- Competition names for ODS rows are resolved in PHP from e3_prod_offer.competition.
SET timezone = 'Europe/London';

DROP SCHEMA IF EXISTS e3_prod_offer CASCADE;
DROP SCHEMA IF EXISTS e3_prod_odsdb CASCADE;

CREATE SCHEMA e3_prod_offer;
CREATE SCHEMA e3_prod_odsdb;

CREATE TABLE e3_prod_offer.competition (
    id   bigint PRIMARY KEY,
    name text NOT NULL
);

CREATE TABLE e3_prod_offer.event (
    id            bigint PRIMARY KEY,
    name          text NOT NULL,
    competitionid bigint NOT NULL REFERENCES e3_prod_offer.competition (id),
    estarttime    timestamp without time zone NOT NULL,
    estatus       jsonb NOT NULL,
    etradestatus  jsonb NOT NULL,
    betting       jsonb NOT NULL,
    type          text NOT NULL
);

CREATE TABLE e3_prod_odsdb.odsevent (
    id            bigint PRIMARY KEY,
    name          text NOT NULL,
    competitionid bigint,
    event         jsonb NOT NULL
);

INSERT INTO e3_prod_offer.competition (id, name) VALUES
    (100, 'Premier League'),
    (200, 'Championship'),
    (300, 'FA Cup'),
    (400, 'La Liga');

-- Reusable JSON builders for times inside / outside the 2h–90d window.
-- included: 3 hours ago; too new: 30 minutes ago; too old: 100 days ago.

-- OFFER included: OFFER-only event (competition 100)
INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    1,
    'Alpha OFFER Only Winner',
    100,
    now() - interval '3 hours',
    '{"mb":"2"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    jsonb_build_object(
        'mb', jsonb_build_object(
            'original', jsonb_build_object(
                'EndTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')
            )
        )
    ),
    '2'
);

-- OFFER included: same event_id as an ODS row (OFFER must win)
INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    10,
    'Echo Duplicate Winner',
    100,
    now() - interval '3 hours',
    '{"mb":"2"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    jsonb_build_object(
        'mb', jsonb_build_object(
            'original', jsonb_build_object(
                'EndTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')
            )
        )
    ),
    '2'
);

-- OFFER excluded: too new
INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    20,
    'ZZ Too New OFFER',
    100,
    now() - interval '30 minutes',
    '{"mb":"2"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    jsonb_build_object(
        'mb', jsonb_build_object(
            'original', jsonb_build_object(
                'EndTime', to_char(now() - interval '30 minutes', 'YYYY-MM-DD HH24:MI:SS')
            )
        )
    ),
    '2'
);

-- OFFER excluded: too old
INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    21,
    'ZZ Too Old OFFER',
    100,
    now() - interval '100 days',
    '{"mb":"2"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    jsonb_build_object(
        'mb', jsonb_build_object(
            'original', jsonb_build_object(
                'EndTime', to_char(now() - interval '100 days', 'YYYY-MM-DD HH24:MI:SS')
            )
        )
    ),
    '2'
);

-- OFFER excluded: status not 2 (uses competition 200 so that 200 is not in the OFFER result)
INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    22,
    'ZZ Wrong Status OFFER',
    200,
    now() - interval '3 hours',
    '{"mb":"1"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    jsonb_build_object(
        'mb', jsonb_build_object(
            'original', jsonb_build_object(
                'EndTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')
            )
        )
    ),
    '2'
);

-- OFFER excluded: type not 2
INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    23,
    'ZZ Wrong Type OFFER',
    100,
    now() - interval '3 hours',
    '{"mb":"2"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    jsonb_build_object(
        'mb', jsonb_build_object(
            'original', jsonb_build_object(
                'EndTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')
            )
        )
    ),
    '1'
);

-- OFFER excluded: betting end time still inside the 2-hour window
INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    24,
    'ZZ Late End OFFER',
    100,
    now() - interval '3 hours',
    '{"mb":"2"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    jsonb_build_object(
        'mb', jsonb_build_object(
            'original', jsonb_build_object(
                'EndTime', to_char(now() - interval '30 minutes', 'YYYY-MM-DD HH24:MI:SS')
            )
        )
    ),
    '2'
);

-- Offered on TRI but missing offer EndTime, so the OFFER query skips it.
-- ODS can still list it (Bravo / Charlie). Spanish (id 50) has no such row.
INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    2,
    'Bravo OFFER stub (no EndTime)',
    100,
    now() - interval '3 hours',
    '{"mb":"2"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    '{}'::jsonb,
    '2'
);

INSERT INTO e3_prod_offer.event
    (id, name, competitionid, estarttime, estatus, etradestatus, betting, type)
VALUES (
    3,
    'Charlie OFFER stub (no EndTime)',
    200,
    now() - interval '4 hours',
    '{"mb":"2"}'::jsonb,
    '{"mb":"2"}'::jsonb,
    '{}'::jsonb,
    '2'
);

-- ODS included: competition already on an OFFER result row (name reused, no extra lookup)
INSERT INTO e3_prod_odsdb.odsevent (id, name, competitionid, event)
VALUES (
    2,
    'Bravo ODS Shared Comp',
    100,
    jsonb_build_object(
        'anticipated', jsonb_build_object('startTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'betting', jsonb_build_object('endTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'type', '2',
        'status', '2',
        'tradeStatus', '2'
    )
);

-- ODS included: competition in offer.competition but not in the OFFER result (second lookup)
INSERT INTO e3_prod_odsdb.odsevent (id, name, competitionid, event)
VALUES (
    3,
    'Charlie ODS Lookup Comp',
    200,
    jsonb_build_object(
        'anticipated', jsonb_build_object('startTime', to_char(now() - interval '4 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'betting', jsonb_build_object('endTime', to_char(now() - interval '4 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'type', '2',
        'status', '2',
        'tradeStatus', '1'
    )
);

-- ODS included: unknown competitionid (blank name)
INSERT INTO e3_prod_odsdb.odsevent (id, name, competitionid, event)
VALUES (
    4,
    'Delta ODS Unknown Comp',
    999,
    jsonb_build_object(
        'anticipated', jsonb_build_object('startTime', to_char(now() - interval '5 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'betting', jsonb_build_object('endTime', to_char(now() - interval '5 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'type', '2',
        'status', '2',
        'tradeStatus', '2'
    )
);

-- ODS included: same event_id as OFFER row 10 (must be skipped; OFFER wins)
INSERT INTO e3_prod_odsdb.odsevent (id, name, competitionid, event)
VALUES (
    10,
    'Echo Duplicate Winner ODS copy',
    100,
    jsonb_build_object(
        'anticipated', jsonb_build_object('startTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'betting', jsonb_build_object('endTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'type', '2',
        'status', '2',
        'tradeStatus', '2'
    )
);

-- ODS fetched but dropped: not offered on TRI (Spanish outright)
INSERT INTO e3_prod_odsdb.odsevent (id, name, competitionid, event)
VALUES (
    50,
    'Spanish La Liga Winner',
    400,
    jsonb_build_object(
        'anticipated', jsonb_build_object('startTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'betting', jsonb_build_object('endTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'type', '2',
        'status', '2',
        'tradeStatus', '2'
    )
);

-- ODS excluded: too new
INSERT INTO e3_prod_odsdb.odsevent (id, name, competitionid, event)
VALUES (
    30,
    'ZZ Too New ODS',
    100,
    jsonb_build_object(
        'anticipated', jsonb_build_object('startTime', to_char(now() - interval '30 minutes', 'YYYY-MM-DD HH24:MI:SS')),
        'betting', jsonb_build_object('endTime', to_char(now() - interval '30 minutes', 'YYYY-MM-DD HH24:MI:SS')),
        'type', '2',
        'status', '2',
        'tradeStatus', '2'
    )
);

-- ODS excluded: too old
INSERT INTO e3_prod_odsdb.odsevent (id, name, competitionid, event)
VALUES (
    31,
    'ZZ Too Old ODS',
    100,
    jsonb_build_object(
        'anticipated', jsonb_build_object('startTime', to_char(now() - interval '100 days', 'YYYY-MM-DD HH24:MI:SS')),
        'betting', jsonb_build_object('endTime', to_char(now() - interval '100 days', 'YYYY-MM-DD HH24:MI:SS')),
        'type', '2',
        'status', '2',
        'tradeStatus', '2'
    )
);

-- ODS excluded: type not 2
INSERT INTO e3_prod_odsdb.odsevent (id, name, competitionid, event)
VALUES (
    32,
    'ZZ Wrong Type ODS',
    100,
    jsonb_build_object(
        'anticipated', jsonb_build_object('startTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'betting', jsonb_build_object('endTime', to_char(now() - interval '3 hours', 'YYYY-MM-DD HH24:MI:SS')),
        'type', '1',
        'status', '2',
        'tradeStatus', '2'
    )
);
