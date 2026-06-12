import Alpine from 'alpinejs'
import {
    Chart,
    LineElement,
    BarElement,
    ArcElement,
    PointElement,
    LineController,
    BarController,
    DoughnutController,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js'

// ─── Chart.js — register only needed components ────────
Chart.register(
    LineElement,
    BarElement,
    ArcElement,
    PointElement,
    LineController,
    BarController,
    DoughnutController,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    Filler,
)

// ─── Chart.js global defaults (dark theme) ─────────────
Chart.defaults.color = '#94a3b8'
Chart.defaults.borderColor = 'rgba(255,255,255,0.06)'
Chart.defaults.font.family = 'ui-sans-serif, system-ui, sans-serif'
Chart.defaults.font.size = 12

// ─── Alpine.js ─────────────────────────────────────────
window.Alpine = Alpine
Alpine.start()

// ─── Chart factory helper (used in admin pages) ────────
window.AdminCharts = {
    /**
     * @param {string} id
     * @param {object} config
     * @returns {Chart}
     */
    make(id, config) {
        const canvas = document.getElementById(id)
        if (!canvas) return null
        return new Chart(canvas, config)
    },

    lineConfig(labels, datasets, options = {}) {
        return {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: datasets.length > 1 } },
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,0.04)' } },
                    y: { grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true },
                },
                ...options,
            },
        }
    },

    barConfig(labels, datasets, options = {}) {
        return {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true },
                },
                ...options,
            },
        }
    },

    doughnutConfig(labels, data, colors, options = {}) {
        return {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{ data, backgroundColor: colors, borderWidth: 0 }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: { legend: { position: 'bottom' } },
                ...options,
            },
        }
    },
}
