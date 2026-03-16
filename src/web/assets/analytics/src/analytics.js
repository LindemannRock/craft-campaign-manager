(function(window) {
    'use strict';

    window.lrCampaignManagerAnalyticsInit = function(initConfig) {
        const config = initConfig || {};

        if (window.lrCampaignManagerAnalyticsBound) {
            if (window.lrAnalyticsInit) {
                window.lrAnalyticsInit(config);
            }
            return;
        }
        window.lrCampaignManagerAnalyticsBound = true;

        if (window.lrAnalyticsInit) {
            window.lrAnalyticsInit(config);
        }

        const chartColors = window.lrChartColors || [
            '#0d78f2', '#10b981', '#ef4444', '#f59e0b', '#8b5cf6', '#06b6d4',
            '#ec4899', '#84cc16', '#f97316', '#6366f1'
        ];

        const strings = config.strings || {};

        function destroyChart(canvasId, prefix) {
            const chartKey = canvasId.replace(/-/g, '_');
            if (window.lrChartInstances && window.lrChartInstances[prefix] && window.lrChartInstances[prefix][chartKey]) {
                window.lrChartInstances[prefix][chartKey].destroy();
                delete window.lrChartInstances[prefix][chartKey];
            }
        }

        function resetChartState(canvas) {
            if (!canvas) return;
            canvas.style.display = '';
            const parent = canvas.parentNode;
            if (!parent) return;
            parent.querySelectorAll('.zilch').forEach(el => el.remove());
        }

        function renderEmptyState(canvasId, message, prefix) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            resetChartState(canvas);
            destroyChart(canvasId, prefix);
            canvas.style.display = 'none';
            const emptyMsg = document.createElement('div');
            emptyMsg.className = 'zilch';
            emptyMsg.style.padding = '48px 24px';
            emptyMsg.innerHTML = '<p>' + message + '</p>';
            canvas.parentNode.appendChild(emptyMsg);
        }

        function loadAllChartData(campaignId, prefix) {
            const extraParams = {};
            if (campaignId && campaignId !== 'all') {
                extraParams.campaignId = campaignId;
            }

            window.lrLoadChartData('daily', function(data) {
                if (!data || !data.labels) {
                    return;
                }

                const hasRecipients = Array.isArray(data.recipients) && data.recipients.some(v => Number(v) > 0);
                const hasSubmissions = Array.isArray(data.submissions) && data.submissions.some(v => Number(v) > 0);

                if (!hasRecipients && !hasSubmissions) {
                    renderEmptyState('daily-trend-chart', strings.noActivity || 'No activity data available.', prefix);
                    return;
                }

                renderDailyChart(data);
            }, extraParams);

            window.lrLoadChartData('channels', function(data) {
                if (!data || !data.labels || !data.values) {
                    return;
                }
                const hasData = Array.isArray(data.values) && data.values.some(v => Number(v) > 0);
                if (!hasData) {
                    renderEmptyState('channel-chart', strings.noChannel || 'No channel data available.', prefix);
                    return;
                }

                renderChannelChart(data);
            }, extraParams);

            window.lrLoadChartData('engagement', function(data) {
                if (!data || !data.labels) {
                    return;
                }

                const hasEmailOpens = Array.isArray(data.emailOpens) && data.emailOpens.some(v => Number(v) > 0);
                const hasSmsOpens = Array.isArray(data.smsOpens) && data.smsOpens.some(v => Number(v) > 0);

                if (!hasEmailOpens && !hasSmsOpens) {
                    renderEmptyState('engagement-chart', strings.noEngagement || 'No engagement data available.', prefix);
                    return;
                }

                renderEngagementChart(data);
            }, extraParams);

            window.lrLoadChartData('funnel', function(data) {
                if (!data || !data.labels || !data.values) {
                    return;
                }

                const hasData = Array.isArray(data.values) && data.values.some(v => Number(v) > 0);
                if (!hasData) {
                    renderEmptyState('funnel-chart', strings.noFunnel || 'No funnel data available.', prefix);
                    return;
                }

                renderFunnelChart(data);
            }, extraParams);
        }

        function renderDailyChart(data) {
            const canvas = document.getElementById('daily-trend-chart');
            if (!canvas) return;
            resetChartState(canvas);

            window.lrCreateChart('daily-trend-chart', 'line', {
                labels: data.labels,
                datasets: [
                    {
                        label: strings.recipientsLabel || 'Recipients Added',
                        data: data.recipients,
                        borderColor: '#0d78f2',
                        backgroundColor: 'rgba(13, 120, 242, 0.1)',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: strings.responsesLabel || 'Responses',
                        data: data.submissions,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.3,
                        fill: true
                    }
                ]
            }, {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            });
        }

        function renderChannelChart(data) {
            const canvas = document.getElementById('channel-chart');
            if (!canvas) return;
            resetChartState(canvas);

            window.lrCreateChart('channel-chart', 'doughnut', {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: ['#3b82f6', '#8b5cf6', '#10b981']
                }]
            });
        }

        function renderEngagementChart(data) {
            const canvas = document.getElementById('engagement-chart');
            if (!canvas) return;
            resetChartState(canvas);

            window.lrCreateChart('engagement-chart', 'line', {
                labels: data.labels,
                datasets: [
                    {
                        label: strings.emailOpensLabel || 'Email Opens',
                        data: data.emailOpens,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: strings.smsOpensLabel || 'SMS Opens',
                        data: data.smsOpens,
                        borderColor: '#8b5cf6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        tension: 0.3,
                        fill: true
                    }
                ]
            }, {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            });
        }

        function renderFunnelChart(data) {
            const canvas = document.getElementById('funnel-chart');
            if (!canvas) return;
            resetChartState(canvas);

            window.lrCreateChart('funnel-chart', 'bar', {
                labels: data.labels,
                datasets: [{
                    label: strings.countLabel || 'Count',
                    data: data.values,
                    backgroundColor: ['#0d78f2', '#3b82f6', '#8b5cf6', '#10b981']
                }]
            }, {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            });
        }

        document.addEventListener('lr:analyticsInit', function(e) {
            const eventConfig = e.detail && e.detail.config ? e.detail.config : (window.lrAnalyticsConfig || {});
            const campaignId = eventConfig.customFilters ? eventConfig.customFilters.campaign : null;
            const prefix = eventConfig.prefix || 'analytics';
            loadAllChartData(campaignId, prefix);
        });
    };
})(window);
