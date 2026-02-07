/**
 * Vicidial Charts - Chart.js Integration for Analytics
 */

class VicidialCharts {
    constructor() {
        this.charts = {};
        this.colors = {
            cyan: 'rgb(6, 182, 212)',
            blue: 'rgb(59, 130, 246)',
            green: 'rgb(34, 197, 94)',
            purple: 'rgb(168, 85, 247)',
            indigo: 'rgb(99, 102, 241)',
            pink: 'rgb(236, 72, 153)',
            orange: 'rgb(251, 146, 60)',
            red: 'rgb(239, 68, 68)',
            yellow: 'rgb(234, 179, 8)'
        };
    }

    /**
     * Create Top Agents Bar Chart
     */
    createTopAgentsChart(canvasId, data) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
        }

        this.charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(agent => agent.name),
                datasets: [{
                    label: 'Conversiones',
                    data: data.map(agent => agent.conversions),
                    backgroundColor: this.colors.cyan,
                    borderColor: this.colors.cyan,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Top 10 Agentes por Conversiones',
                        color: '#e2e8f0',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            afterLabel: function (context) {
                                const agent = data[context.dataIndex];
                                return [
                                    `Llamadas: ${agent.calls}`,
                                    `Tasa: ${agent.conversion_rate}%`
                                ];
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#94a3b8'
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.1)'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#94a3b8',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    /**
     * Create Time Distribution Pie Chart
     */
    createTimeDistributionChart(canvasId, data) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
        }

        const labels = Object.keys(data);
        const values = Object.values(data);

        this.charts[canvasId] = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: [
                        this.colors.cyan,
                        this.colors.orange,
                        this.colors.yellow,
                        this.colors.purple,
                        this.colors.red
                    ],
                    borderWidth: 2,
                    borderColor: '#1e293b'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: '#e2e8f0',
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    },
                    title: {
                        display: true,
                        text: 'Distribución de Tiempo',
                        color: '#e2e8f0',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const seconds = context.parsed;
                                const hours = Math.floor(seconds / 3600);
                                const minutes = Math.floor((seconds % 3600) / 60);
                                return `${context.label}: ${hours}h ${minutes}m`;
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Create Disposition Breakdown Bar Chart
     */
    createDispositionChart(canvasId, data) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
        }

        const labels = Object.keys(data);
        const values = Object.values(data);

        this.charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Cantidad',
                    data: values,
                    backgroundColor: [
                        this.colors.green,
                        this.colors.cyan,
                        this.colors.blue,
                        this.colors.yellow,
                        this.colors.orange,
                        this.colors.red,
                        this.colors.pink,
                        this.colors.purple
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Desglose de Disposiciones',
                        color: '#e2e8f0',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            color: '#94a3b8'
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.1)'
                        }
                    },
                    y: {
                        ticks: {
                            color: '#94a3b8'
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    /**
     * Create Trends Line Chart
     */
    createTrendsChart(canvasId, data) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
        }

        this.charts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => d.date),
                datasets: [
                    {
                        label: 'Llamadas',
                        data: data.map(d => d.calls),
                        borderColor: this.colors.cyan,
                        backgroundColor: 'rgba(6, 182, 212, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Conversiones',
                        data: data.map(d => d.conversions),
                        borderColor: this.colors.green,
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#e2e8f0',
                            padding: 15
                        }
                    },
                    title: {
                        display: true,
                        text: 'Tendencias por Fecha',
                        color: '#e2e8f0',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#94a3b8'
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.1)'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#94a3b8'
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    /**
     * Create Agent Radar Chart
     */
    createRadarChart(canvasId, agentData) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
        }

        this.charts[canvasId] = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: ['Conversión', 'Productividad', 'Ocupación', 'Eficiencia', 'Contacto'],
                datasets: [{
                    label: agentData.name,
                    data: [
                        agentData.normalized.conversion_rate,
                        agentData.normalized.productivity,
                        agentData.normalized.occupancy,
                        agentData.normalized.efficiency,
                        agentData.normalized.contact_rate
                    ],
                    backgroundColor: 'rgba(6, 182, 212, 0.2)',
                    borderColor: this.colors.cyan,
                    borderWidth: 2,
                    pointBackgroundColor: this.colors.cyan,
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: this.colors.cyan
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#e2e8f0'
                        }
                    },
                    title: {
                        display: true,
                        text: 'Perfil de Rendimiento',
                        color: '#e2e8f0',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                },
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            color: '#94a3b8',
                            backdropColor: 'transparent'
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.2)'
                        },
                        pointLabels: {
                            color: '#e2e8f0',
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Create Comparison Bar Chart
     */
    createComparisonChart(canvasId, comparisons) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
        }

        const metrics = Object.keys(comparisons);
        const currentValues = metrics.map(key => comparisons[key].current);
        const previousValues = metrics.map(key => comparisons[key].previous);
        const labels = metrics.map(key => comparisons[key].label);

        this.charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Período Actual',
                        data: currentValues,
                        backgroundColor: this.colors.cyan,
                        borderWidth: 1
                    },
                    {
                        label: 'Período Anterior',
                        data: previousValues,
                        backgroundColor: this.colors.purple,
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#e2e8f0',
                            padding: 15
                        }
                    },
                    title: {
                        display: true,
                        text: 'Comparación de Períodos',
                        color: '#e2e8f0',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#94a3b8'
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.1)'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#94a3b8',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    /**
     * Destroy all charts
     */
    destroyAll() {
        Object.values(this.charts).forEach(chart => chart.destroy());
        this.charts = {};
    }
}

// Initialize global instance
window.vicidialCharts = new VicidialCharts();
