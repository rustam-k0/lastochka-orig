<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RabbitMQ Debug Dashboard</title>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.5; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 15px 20px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header h1 { font-size: 24px; color: #2d3748; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s; }
        .btn-danger { background: #e53e3e; color: white; }
        .btn-danger:hover { background: #c53030; }
        .btn-primary { background: #3182ce; color: white; }
        .btn-primary:hover { background: #2c5282; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }

        /* Filters */
        .filters { display: flex; gap: 12px; flex-wrap: wrap; padding: 15px 20px; background: #fff; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 12px; color: #718096; font-weight: 500; }
        .filter-group select, .filter-group input { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; min-width: 150px; }
        .filter-group select:focus, .filter-group input:focus { outline: none; border-color: #3182ce; box-shadow: 0 0 0 3px rgba(49,130,206,0.1); }

        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 12px; color: #718096; text-transform: uppercase; margin-bottom: 8px; }
        .stat-card .value { font-size: 28px; font-weight: 700; color: #2d3748; }
        .stat-card .sub { font-size: 13px; color: #a0aec0; margin-top: 4px; }
        .stat-card .sub .error { color: #e53e3e; }

        /* Main content */
        .main-content { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 1200px) { .main-content { grid-template-columns: 1fr; } }

        /* Messages list */
        .messages-panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; }
        .panel-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #2d3748; display: flex; justify-content: space-between; align-items: center; }
        .messages-list { max-height: 600px; overflow-y: auto; }
        .message-row { padding: 12px 20px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: background 0.15s; display: flex; align-items: center; gap: 12px; }
        .message-row:hover { background: #f7fafc; }
        .message-row.active { background: #ebf8ff; }
        .message-row.failed { background: #fff5f5; }
        .message-row.failed:hover { background: #fed7d7; }

        .direction-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
        .direction-icon.inbound { background: #c6f6d5; color: #276749; }
        .direction-icon.outbound { background: #bee3f8; color: #2b6cb0; }
        .direction-icon.failed { background: #fed7d7; color: #c53030; }

        .message-info { flex: 1; min-width: 0; }
        .message-info .routing-key { font-weight: 500; color: #2d3748; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .message-info .entity { font-size: 12px; color: #718096; }
        .message-time { font-size: 12px; color: #a0aec0; white-space: nowrap; }
        .message-duration { font-size: 11px; color: #a0aec0; background: #edf2f7; padding: 2px 6px; border-radius: 4px; }

        /* Details panel */
        .details-panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .details-content { padding: 20px; }
        .details-empty { padding: 60px 20px; text-align: center; color: #a0aec0; }

        .detail-row { margin-bottom: 16px; }
        .detail-row label { font-size: 12px; color: #718096; text-transform: uppercase; display: block; margin-bottom: 4px; }
        .detail-row .value { font-size: 14px; color: #2d3748; }

        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-badge.processed { background: #c6f6d5; color: #276749; }
        .status-badge.failed { background: #fed7d7; color: #c53030; }
        .status-badge.pending { background: #fefcbf; color: #975a16; }

        /* JSON viewer */
        .json-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
        @media (max-width: 1400px) { .json-panels { grid-template-columns: 1fr; } }
        .json-panel { background: #1a202c; border-radius: 8px; overflow: hidden; }
        .json-panel-header { padding: 10px 16px; background: #2d3748; color: #e2e8f0; font-size: 12px; font-weight: 600; }
        .json-content { padding: 16px; color: #e2e8f0; font-family: 'Monaco', 'Menlo', monospace; font-size: 12px; line-height: 1.6; overflow-x: auto; white-space: pre-wrap; word-break: break-all; max-height: 300px; overflow-y: auto; }

        /* Error display */
        .error-panel { background: #fff5f5; border: 1px solid #fed7d7; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .error-panel h4 { color: #c53030; margin-bottom: 8px; font-size: 14px; }
        .error-panel .error-message { color: #742a2a; font-size: 14px; margin-bottom: 12px; }
        .error-panel .error-trace { font-family: monospace; font-size: 11px; color: #9b2c2c; background: #fff; padding: 12px; border-radius: 4px; max-height: 200px; overflow: auto; white-space: pre-wrap; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; gap: 8px; padding: 16px; border-top: 1px solid #e2e8f0; }
        .pagination button { padding: 6px 12px; border: 1px solid #e2e8f0; background: #fff; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .pagination button:hover:not(:disabled) { background: #f7fafc; border-color: #cbd5e0; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination .page-info { padding: 6px 12px; font-size: 13px; color: #718096; }

        /* Loading */
        .loading { text-align: center; padding: 40px; color: #718096; }
        .spinner { display: inline-block; width: 20px; height: 20px; border: 2px solid #e2e8f0; border-top-color: #3182ce; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="container" x-data="rabbitDebug()">
    <!-- Header -->
    <div class="header">
        <h1>RabbitMQ Debug Dashboard</h1>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-primary btn-sm" @click="loadMessages()" :disabled="loading">
                <span x-show="loading" class="spinner" style="width: 14px; height: 14px; border-width: 2px;"></span>
                <span x-show="!loading">Refresh</span>
            </button>
            <button class="btn btn-danger btn-sm" @click="clearLogs()" :disabled="loading">Clear Old Logs</button>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div class="filter-group">
            <label>Direction</label>
            <select x-model="filters.direction" @change="loadMessages()">
                <option value="">All</option>
                <option value="inbound">Inbound</option>
                <option value="outbound">Outbound</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select x-model="filters.status" @change="loadMessages()">
                <option value="">All</option>
                <option value="processed">Processed</option>
                <option value="failed">Failed</option>
                <option value="pending">Pending</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Routing Key</label>
            <input type="text" x-model="filters.routing_key" @input.debounce.500ms="loadMessages()" placeholder="product.*, order.*">
        </div>
        <div class="filter-group">
            <label>Entity Type</label>
            <input type="text" x-model="filters.entity_type" @input.debounce.500ms="loadMessages()" placeholder="products, orders">
        </div>
        <div class="filter-group">
            <label>Date From</label>
            <input type="datetime-local" x-model="filters.date_from" @change="loadMessages()">
        </div>
        <div class="filter-group">
            <label>Date To</label>
            <input type="datetime-local" x-model="filters.date_to" @change="loadMessages()">
        </div>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <h3>Inbound (24h)</h3>
            <div class="value" x-text="stats.inbound?.total ?? '-'"></div>
            <div class="sub">
                <span x-text="stats.inbound?.processed ?? 0"></span> processed
                <template x-if="stats.inbound?.failed > 0">
                    <span class="error"> / <span x-text="stats.inbound?.failed"></span> failed</span>
                </template>
            </div>
        </div>
        <div class="stat-card">
            <h3>Outbound (24h)</h3>
            <div class="value" x-text="stats.outbound?.total ?? '-'"></div>
            <div class="sub">
                <span x-text="stats.outbound?.processed ?? 0"></span> processed
                <template x-if="stats.outbound?.failed > 0">
                    <span class="error"> / <span x-text="stats.outbound?.failed"></span> failed</span>
                </template>
            </div>
        </div>
        <div class="stat-card">
            <h3>Avg Processing Time</h3>
            <div class="value"><span x-text="stats.avg_processing_time_ms ?? '-'"></span><span x-show="stats.avg_processing_time_ms" style="font-size: 14px; color: #718096;">ms</span></div>
        </div>
    </div>

    <!-- Main content -->
    <div class="main-content">
        <!-- Messages list -->
        <div class="messages-panel">
            <div class="panel-header">
                <span>Messages</span>
                <span style="font-size: 12px; color: #718096;" x-text="'Total: ' + meta.total"></span>
            </div>

            <template x-if="loading && messages.length === 0">
                <div class="loading">
                    <span class="spinner"></span>
                    <div style="margin-top: 10px;">Loading...</div>
                </div>
            </template>

            <div class="messages-list">
                <template x-for="msg in messages" :key="msg.id">
                    <div class="message-row"
                         :class="{ 'active': selectedId === msg.id, 'failed': msg.status === 'failed' }"
                         @click="selectMessage(msg.id)">
                        <div class="direction-icon" :class="msg.status === 'failed' ? 'failed' : msg.direction">
                            <template x-if="msg.status === 'failed'">
                                <span>!</span>
                            </template>
                            <template x-if="msg.status !== 'failed' && msg.direction === 'inbound'">
                                <span>&darr;</span>
                            </template>
                            <template x-if="msg.status !== 'failed' && msg.direction === 'outbound'">
                                <span>&uarr;</span>
                            </template>
                        </div>
                        <div class="message-info">
                            <div class="routing-key" x-text="msg.routing_key"></div>
                            <div class="entity">
                                <span x-show="msg.entity_type" x-text="msg.entity_type + ' #' + msg.entity_id"></span>
                                <span x-show="!msg.entity_type && msg.external_id" x-text="'ext: ' + msg.external_id"></span>
                            </div>
                        </div>
                        <div class="message-time" x-text="formatTime(msg.created_at)"></div>
                        <div class="message-duration" x-show="msg.processing_time_ms" x-text="msg.processing_time_ms + 'ms'"></div>
                    </div>
                </template>
            </div>

            <!-- Pagination -->
            <div class="pagination" x-show="meta.last_page > 1">
                <button @click="goToPage(1)" :disabled="meta.current_page === 1">&laquo;</button>
                <button @click="goToPage(meta.current_page - 1)" :disabled="meta.current_page === 1">&lsaquo;</button>
                <span class="page-info" x-text="meta.current_page + ' / ' + meta.last_page"></span>
                <button @click="goToPage(meta.current_page + 1)" :disabled="meta.current_page === meta.last_page">&rsaquo;</button>
                <button @click="goToPage(meta.last_page)" :disabled="meta.current_page === meta.last_page">&raquo;</button>
            </div>
        </div>

        <!-- Details panel -->
        <div class="details-panel">
            <div class="panel-header">Message Details</div>

            <template x-if="!selectedMessage">
                <div class="details-empty">Select a message to view details</div>
            </template>

            <template x-if="detailsLoading">
                <div class="loading">
                    <span class="spinner"></span>
                </div>
            </template>

            <template x-if="selectedMessage && !detailsLoading">
                <div class="details-content">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="detail-row">
                            <label>Routing Key</label>
                            <div class="value" x-text="selectedMessage.routing_key"></div>
                        </div>
                        <div class="detail-row">
                            <label>Status</label>
                            <div class="value">
                                <span class="status-badge" :class="selectedMessage.status" x-text="selectedMessage.status"></span>
                                <span x-show="selectedMessage.processing_time_ms" style="margin-left: 8px; color: #718096; font-size: 12px;" x-text="'(' + selectedMessage.processing_time_ms + 'ms)'"></span>
                            </div>
                        </div>
                        <div class="detail-row">
                            <label>Direction</label>
                            <div class="value" x-text="selectedMessage.direction"></div>
                        </div>
                        <div class="detail-row" x-show="selectedMessage.queue_name">
                            <label>Queue</label>
                            <div class="value" x-text="selectedMessage.queue_name"></div>
                        </div>
                        <div class="detail-row" x-show="selectedMessage.entity_type">
                            <label>Entity</label>
                            <div class="value" x-text="selectedMessage.entity_type + ' #' + selectedMessage.entity_id"></div>
                        </div>
                        <div class="detail-row" x-show="selectedMessage.external_id">
                            <label>External ID</label>
                            <div class="value" x-text="selectedMessage.external_id"></div>
                        </div>
                    </div>

                    <!-- Error panel -->
                    <template x-if="selectedMessage.status === 'failed'">
                        <div class="error-panel">
                            <h4>Error</h4>
                            <div class="error-message" x-text="selectedMessage.error_message"></div>
                            <template x-if="selectedMessage.error_trace">
                                <details>
                                    <summary style="cursor: pointer; color: #c53030; font-size: 12px; margin-bottom: 8px;">Show Stack Trace</summary>
                                    <div class="error-trace" x-text="selectedMessage.error_trace"></div>
                                </details>
                            </template>
                        </div>
                    </template>

                    <!-- JSON panels -->
                    <div class="json-panels">
                        <div class="json-panel">
                            <div class="json-panel-header">Payload (received)</div>
                            <div class="json-content" x-text="formatJson(selectedMessage.payload)"></div>
                        </div>
                        <template x-if="selectedMessage.db_snapshot">
                            <div class="json-panel">
                                <div class="json-panel-header">DB Snapshot (result)</div>
                                <div class="json-content" x-text="formatJson(selectedMessage.db_snapshot)"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function rabbitDebug() {
    return {
        messages: [],
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
        stats: {},
        filters: {
            direction: '',
            status: '',
            routing_key: '',
            entity_type: '',
            date_from: '',
            date_to: ''
        },
        loading: false,
        detailsLoading: false,
        selectedId: null,
        selectedMessage: null,

        init() {
            this.loadMessages();
            this.loadStats();
            // Auto-refresh every 5 seconds
            setInterval(() => {
                this.loadMessages();
                this.loadStats();
            }, 5000);
        },

        async loadMessages(page = 1) {
            this.loading = true;
            try {
                const params = new URLSearchParams();
                params.append('page', page);
                if (this.filters.direction) params.append('direction', this.filters.direction);
                if (this.filters.status) params.append('status', this.filters.status);
                if (this.filters.routing_key) params.append('routing_key', this.filters.routing_key);
                if (this.filters.entity_type) params.append('entity_type', this.filters.entity_type);
                if (this.filters.date_from) params.append('date_from', new Date(this.filters.date_from).toISOString());
                if (this.filters.date_to) params.append('date_to', new Date(this.filters.date_to).toISOString());

                const response = await fetch('/api/debug/rabbit/messages?' + params.toString());
                const data = await response.json();
                this.messages = data.data;
                this.meta = data.meta;
            } catch (e) {
                console.error('Failed to load messages:', e);
            } finally {
                this.loading = false;
            }
        },

        async loadStats() {
            try {
                const response = await fetch('/api/debug/rabbit/stats');
                this.stats = await response.json();
            } catch (e) {
                console.error('Failed to load stats:', e);
            }
        },

        async selectMessage(id) {
            this.selectedId = id;
            this.detailsLoading = true;
            try {
                const response = await fetch('/api/debug/rabbit/messages/' + id);
                this.selectedMessage = await response.json();
            } catch (e) {
                console.error('Failed to load message details:', e);
            } finally {
                this.detailsLoading = false;
            }
        },

        goToPage(page) {
            if (page >= 1 && page <= this.meta.last_page) {
                this.loadMessages(page);
            }
        },

        async clearLogs() {
            if (!confirm('Are you sure you want to delete old logs?')) return;

            try {
                const response = await fetch('/api/debug/rabbit/messages', { method: 'DELETE' });
                const data = await response.json();
                alert(data.message);
                this.loadMessages();
                this.loadStats();
            } catch (e) {
                console.error('Failed to clear logs:', e);
                alert('Failed to clear logs');
            }
        },

        formatTime(isoString) {
            const date = new Date(isoString);
            return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        },

        formatJson(obj) {
            if (!obj) return '';
            try {
                return JSON.stringify(obj, null, 2);
            } catch (e) {
                return String(obj);
            }
        }
    };
}
</script>
</body>
</html>
