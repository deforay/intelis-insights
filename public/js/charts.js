// Add this to your chat.php view or create a separate charts.js file

class MedicalChartGenerator {
    constructor() {
        this.charts = new Map(); // Store chart instances
        this.initializeECharts();
    }

    initializeECharts() {
        // Add ECharts CDN to your HTML head if not already present
        if (typeof echarts === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js';
            script.onload = () => console.log('ECharts loaded');
            document.head.appendChild(script);
        }
    }

    // Call this after your existing table rendering
    addChartButton(responseData, tableContainer) {
        // Only add button if charts are suggested
        if (!responseData.chart_suggestions || !responseData.chart_suggestions.suitable_for_charts) {
            return;
        }

        const chartButton = document.createElement('button');
        chartButton.className = 'btn btn-primary mt-2';
        chartButton.innerHTML = '📊 Generate Chart';
        chartButton.onclick = () => this.showChartOptions(responseData);

        tableContainer.appendChild(chartButton);
    }

    showChartOptions(responseData) {
        const suggestions = responseData.chart_suggestions.suggestions;

        if (suggestions.length === 0) {
            alert('No suitable chart types found for this data.');
            return;
        }

        // Create modal or inline options
        const optionsDiv = document.createElement('div');
        optionsDiv.className = 'chart-options mt-3 p-3 border rounded';
        optionsDiv.innerHTML = '<h5>Choose Chart Type:</h5>';

        suggestions.forEach((suggestion, index) => {
            const optionButton = document.createElement('button');
            optionButton.className = 'btn btn-outline-secondary me-2 mb-2';
            optionButton.innerHTML = `
                <strong>${suggestion.title}</strong><br>
                <small>${suggestion.description}</small>
            `;
            optionButton.onclick = () => this.generateChart(responseData, suggestion, index);
            optionsDiv.appendChild(optionButton);
        });

        // Find where to insert options (after the table) 
        const tableContainer = document.querySelector('.ai-message:last-child');

        // Remove existing options
        const existing = tableContainer.querySelector('.chart-options');
        if (existing) existing.remove();

        tableContainer.appendChild(optionsDiv);
    }

    async generateChart(responseData, suggestion, chartIndex) {
        try {
            // Show loading state
            this.showChartLoading();

            // Call your PHP endpoint to get formatted chart data
            const chartData = await this.fetchChartData(responseData.rows, suggestion.config);

            // Create chart container
            const chartContainer = this.createChartContainer(chartIndex);

            // Initialize ECharts
            const chart = echarts.init(chartContainer);

            // Build complete ECharts option
            const option = this.buildEChartsOption(suggestion.config, chartData);

            // Render chart
            chart.setOption(option);

            // Store chart instance for cleanup
            this.charts.set(`chart_${chartIndex}`, chart);

            // Handle window resize
            window.addEventListener('resize', () => chart.resize());

            this.hideChartLoading();

        } catch (error) {
            console.error('Chart generation failed:', error);
            this.showChartError(error.message);
        }
    }

    async fetchChartData(rows, chartConfig) {
        // Format data client-side to avoid additional server call
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

        // Handle "none" grouping or missing columns
        if (!groupingColumn || groupingColumn === "none" || !rows[0] || !rows[0][groupingColumn]) {
            // Simple single series - match actual column names
            const actualXColumn = this.findActualColumnName(rows[0], xColumn);
            const actualYColumn = this.findActualColumnName(rows[0], yColumn);

            const categories = rows.map(row => row[actualXColumn]);
            const values = rows.map(row => parseFloat(row[actualYColumn]) || 0);
            return { categories, values };
        }

        // Grouped data logic (for when there's actual grouping)
        const facilityData = {};
        const years = new Set();

        rows.forEach(row => {
            const facility = row[xColumn];
            const yearValue = row[groupingColumn];

            if (yearValue === undefined || yearValue === null) {
                console.error('Grouping column value is undefined:', groupingColumn, row);
                return;
            }

            const year = yearValue.toString();
            const value = parseFloat(row[yColumn]) || 0;

            years.add(year);

            if (!facilityData[facility]) {
                facilityData[facility] = {};
            }
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

    // Helper method to find actual column names
    findActualColumnName(row, configColumnName) {
        if (row[configColumnName]) {
            return configColumnName;
        }

        // Map common mismatches
        const columnMappings = {
            'facility_name': ['Collection Site', 'facility_name', 'Facility Name'],
            'total_tests': ['Total Tests', 'total_tests', 'Total_Tests'],
            'year': ['Year', 'year']
        };

        const possibleNames = columnMappings[configColumnName] || [configColumnName];

        for (const name of possibleNames) {
            if (row[name] !== undefined) {
                return name;
            }
        }

        // Fallback to first available column if no match
        return Object.keys(row)[0];
    }

    formatPieData(rows, config) {
        const labelColumn = config.label_column;
        const valueColumn = config.value_column;

        const data = rows.map(row => ({
            name: row[labelColumn],
            value: parseFloat(row[valueColumn]) || 0
        }));

        return { data };
    }

    buildEChartsOption(config, chartData) {
        const baseOption = JSON.parse(JSON.stringify(config.echarts_option));

        switch (config.chart_type) {
            case 'bar':
            case 'line':
                if (chartData.series) {
                    baseOption.xAxis.data = chartData.categories;
                    baseOption.series = chartData.series;
                    if (!baseOption.legend) baseOption.legend = {};
                    baseOption.legend.show = true;
                } else {
                    baseOption.xAxis.data = chartData.categories;
                    baseOption.series = [{
                        type: config.chart_type,
                        name: config.y_axis.replace('_', ' '),
                        data: chartData.values,
                        itemStyle: { color: '#5470c6' }
                    }];
                }
                break;

            case 'pie':
                if (baseOption.series && baseOption.series[0]) {
                    baseOption.series[0].data = chartData.data;
                }
                break;
        }

        return {
            ...baseOption,
            backgroundColor: '#ffffff',
            grid: {
                left: '10%',
                right: '10%',
                bottom: '20%',
                top: '15%',
                containLabel: true
            },
            textStyle: {
                fontFamily: 'Arial, sans-serif',
                fontSize: 12
            },
            // Better responsive behavior
            xAxis: {
                ...baseOption.xAxis,
                axisLabel: {
                    ...baseOption.xAxis.axisLabel,
                    fontSize: 11,
                    interval: 0,
                    rotate: 45,
                    textStyle: {
                        fontSize: 11
                    }
                }
            },
            yAxis: {
                ...baseOption.yAxis,
                axisLabel: {
                    fontSize: 11
                }
            }
        };
    }

    createChartContainer(chartIndex) {
        // Remove existing chart options
        const existingOptions = document.querySelector('.chart-options');
        if (existingOptions) existingOptions.remove();

        // Create chart container with better sizing
        const chartDiv = document.createElement('div');
        chartDiv.id = `chart_${chartIndex}`;
        chartDiv.className = 'medical-chart border rounded p-2';
        chartDiv.style.cssText = `
        width: 100%;
        height: 500px;
        margin-top: 20px;
        min-height: 400px;
        position: relative;
    `;

        // Add close button
        const closeButton = document.createElement('button');
        closeButton.innerHTML = '×';
        closeButton.className = 'btn btn-sm btn-outline-secondary';
        closeButton.style.cssText = `
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 10;
        width: 30px;
        height: 30px;
        padding: 0;
        line-height: 1;
    `;
        closeButton.onclick = () => this.removeChart(chartIndex);
        chartDiv.appendChild(closeButton);

        // Find insertion point
        const tableContainer = document.querySelector('.ai-message:last-child');
        tableContainer.appendChild(chartDiv);

        return chartDiv;
    }

    removeChart(chartIndex) {
        const chartContainer = document.getElementById(`chart_${chartIndex}`);
        if (chartContainer) {
            chartContainer.remove();
        }

        // Dispose chart instance to free memory
        const chart = this.charts.get(`chart_${chartIndex}`);
        if (chart) {
            chart.dispose();
            this.charts.delete(`chart_${chartIndex}`);
        }
    }

    showChartLoading() {
        // Add loading spinner if needed
        console.log('Generating chart...');
    }

    hideChartLoading() {
        console.log('Chart generated successfully');
    }

    showChartError(message) {
        alert(`Chart generation failed: ${message}`);
    }

    // Cleanup all charts when needed
    disposeAllCharts() {
        this.charts.forEach(chart => chart.dispose());
        this.charts.clear();
    }
}

// Initialize chart generator
const chartGenerator = new MedicalChartGenerator();

// Modify your existing response handler to include chart button
function handleQueryResponse(data) {
    // Your existing table rendering code...

    // Add chart button if charts are available
    if (data.chart_suggestions) {
        const tableContainer = document.querySelector('.response:last-child');
        chartGenerator.addChartButton(data, tableContainer);
    }
}

// Example usage in your existing chat interface:
/*
fetch('/ask', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({q: userQuery})
})
.then(response => response.json())
.then(data => {
    // Render table as usual
    renderTable(data);
    
    // Add chart functionality
    handleQueryResponse(data);
})
.catch(error => console.error('Error:', error));
*/