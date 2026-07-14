CREATE TABLE IF NOT EXISTS proactiveha_vcenter (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    url VARCHAR(512) NOT NULL,
    username VARCHAR(255) NOT NULL,
    password TEXT NOT NULL,
    verify_ssl INTEGER NOT NULL DEFAULT 1,
    api_version VARCHAR(20),
    provider_key VARCHAR(255),
    provider_registered INTEGER NOT NULL DEFAULT 0,
    last_connection TIMESTAMP,
    last_session_refresh TIMESTAMP,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS proactiveha_cluster (
    id SERIAL PRIMARY KEY,
    vcenter_id INTEGER NOT NULL REFERENCES proactiveha_vcenter(id) ON DELETE CASCADE,
    mo_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    cluster_mode VARCHAR(20) NOT NULL DEFAULT 'Manual',
    moderate_remediation VARCHAR(20) NOT NULL DEFAULT 'QuarantineMode',
    severe_remediation VARCHAR(20) NOT NULL DEFAULT 'QuarantineMode',
    min_non_red_hosts INTEGER NOT NULL DEFAULT 1,
    provider_enabled INTEGER NOT NULL DEFAULT 0,
    last_enabled_at TIMESTAMP,
    last_disabled_at TIMESTAMP,
    last_error TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (vcenter_id, mo_id)
);

CREATE TABLE IF NOT EXISTS proactiveha_mapping (
    id SERIAL PRIMARY KEY,
    vcenter_id INTEGER NOT NULL REFERENCES proactiveha_vcenter(id) ON DELETE CASCADE,
    cluster_id INTEGER REFERENCES proactiveha_cluster(id) ON DELETE SET NULL,
    bp_config_name VARCHAR(255) NOT NULL,
    bp_node_name VARCHAR(255) NOT NULL,
    vsphere_host_name VARCHAR(255) NOT NULL,
    vsphere_host_uuid VARCHAR(255),
    vsphere_host_moid VARCHAR(255),
    uuid_last_resolved TIMESTAMP,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (vcenter_id, vsphere_host_name)
);

CREATE TABLE IF NOT EXISTS proactiveha_state (
    id SERIAL PRIMARY KEY,
    mapping_id INTEGER NOT NULL UNIQUE REFERENCES proactiveha_mapping(id) ON DELETE CASCADE,
    desired_state SMALLINT NOT NULL DEFAULT 0,
    desired_state_name VARCHAR(20) NOT NULL DEFAULT 'OK',
    vsphere_state VARCHAR(20) NOT NULL DEFAULT 'green',
    last_evaluated TIMESTAMP,
    last_pushed TIMESTAMP,
    push_status VARCHAR(20) NOT NULL DEFAULT 'synced',
    push_attempts INTEGER NOT NULL DEFAULT 0,
    retry_at TIMESTAMP,
    last_error TEXT,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_proactiveha_state_push_status ON proactiveha_state (push_status, retry_at);

CREATE TABLE IF NOT EXISTS proactiveha_log (
    id SERIAL PRIMARY KEY,
    mapping_id INTEGER REFERENCES proactiveha_mapping(id) ON DELETE SET NULL,
    vcenter_id INTEGER REFERENCES proactiveha_vcenter(id) ON DELETE SET NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    level VARCHAR(10) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    context TEXT
);

CREATE INDEX IF NOT EXISTS idx_proactiveha_log_timestamp ON proactiveha_log (timestamp);
CREATE INDEX IF NOT EXISTS idx_proactiveha_log_level ON proactiveha_log (level);
CREATE INDEX IF NOT EXISTS idx_proactiveha_log_mapping ON proactiveha_log (mapping_id);

CREATE TABLE IF NOT EXISTS proactiveha_sync_run (
    id VARCHAR(32) PRIMARY KEY,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    mappings_processed INTEGER NOT NULL DEFAULT 0,
    mappings_failed INTEGER NOT NULL DEFAULT 0,
    message TEXT
);

CREATE INDEX IF NOT EXISTS idx_proactiveha_sync_run_started ON proactiveha_sync_run (started_at);
