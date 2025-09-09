class MedicalChartGenerator {
    constructor() {
        this.charts = new Map(); // Map<HTMLElement, EChartsInstance>
        this.initializeECharts();
    }

    initializeECharts() {
        if (typeof echarts === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js';
            script.onload = () => console.log('ECharts loaded');
            document.head.appendChild(script);
        }
    }

    addChartButton(responseData, tableContainer) {
        if (!responseData.chart_suggestions || !responseData.chart_suggestions.suitable_for_charts) return;

        // Skip if already present
        if (tableContainer.querySelector('.generate-chart-btn')) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-link btn-sm p-0 ms-2 link-icon generate-chart-btn';
        btn.innerHTML = '<i>📊</i> Generate Chart';
        btn.onclick = (e) => this.showChartOptions(responseData, e.currentTarget.closest('.ai-message'));

        const timingInfo = tableContainer.querySelector('.timing-info');
        if (timingInfo) timingInfo.appendChild(btn);
        else tableContainer.insertBefore(btn, tableContainer.firstChild);
    }

    showChartOptions(responseData, hostMessageEl) {
        const suggestions = responseData.chart_suggestions?.suggestions || [];
        if (!suggestions.length) {
            alert('No suitable chart types found for this data.');
            return;
        }

        const container = hostMessageEl || document.querySelector('.ai-message:last-child') || document.body;

        // Remove existing panel before adding a new one
        const existing = container.querySelector('.chart-panel');
        if (existing) existing.remove();

        const panel = document.createElement('div');
        panel.className = 'chart-panel';

        // Header
        const header = document.createElement('div');
        header.className = 'chart-panel__header';

        const h = document.createElement('h5');
        h.className = 'chart-panel__title';
        h.textContent = 'Choose Chart Type';

        const closeBtn = document.createElement('button');
        closeBtn.className = 'chart-panel__close';
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Close chart options');
        closeBtn.textContent = '×';
        closeBtn.onclick = () => panel.remove();

        header.appendChild(h);
        header.appendChild(closeBtn);

        // Grid
        const grid = document.createElement('div');
        grid.className = 'chart-options-grid';

        suggestions.forEach((sugg, i) => {
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'chart-card';
            card.setAttribute('aria-pressed', 'false');
            card.innerHTML = `
                <div class="chart-card__title">${sugg.title}</div>
                <div class="chart-card__desc">${sugg.description || ''}</div>
            `;

            card.onclick = () => {
                // Reset all
                grid.querySelectorAll('.chart-card').forEach(el => el.setAttribute('aria-pressed', 'false'));
                card.setAttribute('aria-pressed', 'true');

                // Generate (reuse container)
                this.generateChart(responseData, sugg, container); // ✅ pass the host message element

            };

            grid.appendChild(card);
        });

        panel.appendChild(header);
        panel.appendChild(grid);
        container.appendChild(panel);
    }

    async generateChart(responseData, suggestion, hostMessageEl) {
        this.showChartLoading();

        const chartData = await this.fetchChartData(responseData.rows, suggestion.config);
        const chartContainer = this.createChartContainer(hostMessageEl);

        let chart = this.charts.get(chartContainer);
        if (!chart) {
            chart = echarts.init(chartContainer);
            this.charts.set(chartContainer, chart);
            window.addEventListener('resize', () => chart.resize());
        }

        const option = this.buildEChartsOption(suggestion.config, chartData);
        chart.clear();
        chart.setOption(option, { notMerge: true, replaceMerge: ['series', 'xAxis', 'yAxis', 'legend', 'grid', 'dataset'] });
        chart.resize();

        this.hideChartLoading();
    }

    async fetchChartData(rows, chartConfig) {
        return this.formatDataForECharts(rows, chartConfig);
    }

    formatDataForECharts(rows, config) {
        switch (config.chart_type) {
            case 'bar':
            case 'line':
                return this.formatCategoryValueData(rows, config);
            case 'pie':
                return this.formatPieData(rows, config);
            default:
                throw new Error(`Unsupported chart type: ${config.chart_type}`);
        }
    }

    formatCategoryValueData(rows, config) {
        const xColumn = config.x_axis;
        const yColumn = config.y_axis;
        const groupingColumn = config.grouping_column;

        if (!groupingColumn || groupingColumn === "none" || !rows[0] || !rows[0][groupingColumn]) {
            const actualXColumn = this.findActualColumnName(rows[0], xColumn);
            const actualYColumn = this.findActualColumnName(rows[0], yColumn);

            const categories = rows.map(row => row[actualXColumn]);
            const values = rows.map(row => parseFloat(row[actualYColumn]) || 0);
            return { categories, values };
        }

        const facilityData = {};
        const years = new Set();

        rows.forEach(row => {
            const facility = row[xColumn];
            const yearValue = row[groupingColumn];
            if (yearValue === undefined || yearValue === null) return;

            const year = yearValue.toString();
            const value = parseFloat(row[yColumn]) || 0;

            years.add(year);
            if (!facilityData[facility]) facilityData[facility] = {};
            facilityData[facility][year] = (facilityData[facility][year] || 0) + value;
        });

        const facilities = Object.keys(facilityData);
        const yearArray = Array.from(years).sort();

        const series = yearArray.map(year => ({
            name: year,
            type: 'bar',
            data: facilities.map(facility => facilityData[facility][year] || 0)
        }));

        return { categories: facilities, series: series };
    }

    findActualColumnName(row, configColumnName) {
        if (row[configColumnName]) return configColumnName;

        const columnMappings = {
            'facility_name': ['Collection Site', 'facility_name', 'Facility Name'],
            'total_tests': ['Total Tests', 'total_tests', 'Total_Tests'],
            'year': ['Year', 'year']
        };

        const possibleNames = columnMappings[configColumnName] || [configColumnName];
        for (const name of possibleNames) {
            if (row[name] !== undefined) return name;
        }
        return Object.keys(row)[0];
    }

    formatPieData(rows, config) {
        const labelColumn = config.label_column;
        const valueColumn = config.value_column;

        return {
            data: rows.map(row => ({
                name: row[labelColumn],
                value: parseFloat(row[valueColumn]) || 0
            }))
        };
    }

    buildEChartsOption(config, chartData) {
        const baseOption = JSON.parse(JSON.stringify(config.echarts_option || {}));
        const chartType = config.chart_type;

        const normalizeAxis = (ax) => {
            if (Array.isArray(ax)) return ax.length ? ax : [{}];
            if (ax && typeof ax === 'object') return [ax];
            return [{}];
        };

        if (chartType === 'bar' || chartType === 'line') {
            const xAxes = normalizeAxis(baseOption.xAxis);
            const yAxes = normalizeAxis(baseOption.yAxis);

            if (chartData.series) {
                xAxes[0].data = chartData.categories || [];
                baseOption.series = chartData.series;
                baseOption.legend = { ...(baseOption.legend || {}), show: true };
            } else {
                xAxes[0].data = chartData.categories || [];
                baseOption.series = [{
                    type: chartType,
                    name: (config.y_axis || 'value').replace('_', ' '),
                    data: chartData.values || []
                }];
            }

            xAxes[0].axisLabel = { ...(xAxes[0].axisLabel || {}), fontSize: 11, interval: 0, rotate: 45 };
            yAxes[0].axisLabel = { ...(yAxes[0].axisLabel || {}), fontSize: 11 };

            baseOption.xAxis = Array.isArray(baseOption.xAxis) ? xAxes : xAxes[0];
            baseOption.yAxis = Array.isArray(baseOption.yAxis) ? yAxes : yAxes[0];
        }

        if (chartType === 'pie') {
            baseOption.series = baseOption.series && baseOption.series.length ? baseOption.series : [{ type: 'pie' }];
            baseOption.series[0].data = chartData.data || [];
        }

        baseOption.backgroundColor = baseOption.backgroundColor || '#ffffff';
        baseOption.grid = { left: '10%', right: '10%', bottom: '20%', top: '15%', containLabel: true, ...(baseOption.grid || {}) };
        baseOption.textStyle = { fontFamily: 'Arial, sans-serif', fontSize: 12, ...(baseOption.textStyle || {}) };

        return baseOption;
    }

    createChartContainer(hostMessageEl) {
        const scope = hostMessageEl || document.querySelector('.ai-message:last-child') || document.body;

        // reuse if present
        let chartDiv = scope.querySelector('.medical-chart');
        if (!chartDiv) {
            chartDiv = document.createElement('div');
            chartDiv.className = 'medical-chart border rounded p-2';
            chartDiv.style.cssText = `
      width: 100%;
      height: 500px;
      margin-top: 20px;
      min-height: 400px;
      position: relative;
    `;
            scope.appendChild(chartDiv);
        }

        // ⬇ ensure toolbar exists every time (first or subsequent calls)
        let toolbar = chartDiv.querySelector('.chart-toolbar');
        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.className = 'chart-toolbar';
            toolbar.style.cssText = `
      position: absolute; top: 8px; right: 8px; z-index: 10;
      display: flex; gap: 6px;
    `;

            const downloadBtn = document.createElement('button');
            downloadBtn.className = 'btn btn-sm btn-outline-secondary';
            downloadBtn.title = 'Download chart as PNG';
            downloadBtn.textContent = '⬇';
            downloadBtn.style.cssText = 'width:30px;height:30px;line-height:1;padding:0;';
            downloadBtn.onclick = () => {
                const chart = this.charts.get(chartDiv);
                if (!chart) return;
                const url = chart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#fff' });
                const a = document.createElement('a');
                a.href = url;
                a.download = 'chart.png';
                a.click();
            };

            const closeBtn = document.createElement('button');
            closeBtn.className = 'btn btn-sm btn-outline-secondary';
            closeBtn.title = 'Close chart';
            closeBtn.textContent = '×';
            closeBtn.style.cssText = 'width:30px;height:30px;line-height:1;padding:0;';
            closeBtn.onclick = () => this.removeChart(chartDiv);

            toolbar.appendChild(downloadBtn);
            toolbar.appendChild(closeBtn);
            chartDiv.appendChild(toolbar);
        }

        return chartDiv;
    }



    removeChart(chartContainer) {
        if (!chartContainer) return;
        const chart = this.charts.get(chartContainer);
        if (chart) {
            chart.dispose();
            this.charts.delete(chartContainer);
        }
        chartContainer.remove();
    }

    showChartLoading() { console.log('Generating chart...'); }
    hideChartLoading() { console.log('Chart generated successfully'); }
    showChartError(message) { alert(`Chart generation failed: ${message}`); }
    disposeAllCharts() {
        this.charts.forEach(chart => chart.dispose());
        this.charts.clear();
    }
}

if (!window.chartGenerator) {
    window.chartGenerator = new MedicalChartGenerator();
}
