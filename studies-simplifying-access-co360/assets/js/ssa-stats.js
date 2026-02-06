(function () {
  function renderChart(container) {
    var series = container.getAttribute('data-series');
    if (!series) {
      return;
    }
    series = JSON.parse(series);
    if (!series.length) {
      container.querySelector('.co360-ssa-chart').innerHTML = '<p>Sin datos.</p>';
      return;
    }

    var chartType = container.getAttribute('data-chart');
    var max = series.reduce(function (acc, item) {
      return Math.max(acc, item.total);
    }, 0);
    var width = 480;
    var height = 180;
    var padding = 20;
    var chartWidth = width - padding * 2;
    var chartHeight = height - padding * 2;

    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
    svg.setAttribute('class', 'co360-ssa-svg');

    if (chartType === 'bar') {
      var barWidth = chartWidth / series.length;
      series.forEach(function (item, index) {
        var barHeight = max ? (item.total / max) * chartHeight : 0;
        var rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', padding + index * barWidth + 4);
        rect.setAttribute('y', padding + (chartHeight - barHeight));
        rect.setAttribute('width', Math.max(4, barWidth - 8));
        rect.setAttribute('height', barHeight);
        rect.setAttribute('class', 'co360-ssa-bar');
        svg.appendChild(rect);
      });
    } else {
      var points = series.map(function (item, index) {
        var x = padding + (chartWidth / (series.length - 1 || 1)) * index;
        var y = padding + (chartHeight - (max ? (item.total / max) * chartHeight : 0));
        return x + ',' + y;
      }).join(' ');

      var polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
      polyline.setAttribute('points', points);
      polyline.setAttribute('class', 'co360-ssa-line');
      svg.appendChild(polyline);
    }

    var chartContainer = container.querySelector('.co360-ssa-chart');
    chartContainer.innerHTML = '';
    chartContainer.appendChild(svg);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.co360-ssa-stats').forEach(renderChart);
  });
})();
