
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

 if((!isset(userProfils.doNotAutoHideMenu) || userProfils.doNotAutoHideMenu != 1) && !jQuery.support.touch){
    $('#sd_objectList').hide();
    $('#div_graphiqueDisplay').removeClass('col-xs-10').addClass('col-xs-12');

    $('#bt_displayObjectList').on('mouseenter',function(){
       var timer = setTimeout(function(){
        $('#bt_displayObjectList').find('i').hide();
        $('#div_graphiqueDisplay').addClass('col-xs-10').removeClass('col-xs-12');
        $('#sd_objectList').show();
        $(window).resize();
    }, 100);
       $(this).data('timerMouseleave', timer)
   }).on("mouseleave", function(){
      clearTimeout($(this).data('timerMouseleave'));
  });

   $('#sd_objectList').on('mouseleave',function(){
     var timer = setTimeout(function(){
       $('#sd_objectList').hide();
       $('#bt_displayObjectList').find('i').show();
       $('#div_graphiqueDisplay').removeClass('col-xs-10').addClass('col-xs-12');
       setTimeout(function(){
        $(window).resize();
       },100);
       setTimeout(function(){
        $(window).resize();
       },300);
        setTimeout(function(){
        $(window).resize();
       },500);
   }, 300);
     $(this).data('timerMouseleave', timer);
 }).on("mouseenter", function(){
  clearTimeout($(this).data('timerMouseleave'));
});
}



$(".in_datepicker").datepicker();

$('#bt_validChangeDate').on('click', function () {
    getDatas(object_id, $('#in_startDate').value(), $('#in_endDate').value());
});

$('#cb_powerLissage').on('change',function(){
    if($(this).value() == 1){
        window.location.href=$(this).attr('data-href')+'&powerDisplay=1h';
    }else{
       window.location.href=$(this).attr('data-href')+'&powerDisplay=15min';   
   }
});

setTimeout(function(){ getDatas(object_id); }, 1);

function getDatas(_object_id, _dateStart, _dateEnd) {
    $.ajax({
        type: 'POST',
        url: 'plugins/energy/core/ajax/energy.ajax.php',
        data: {
            action: 'getData',
            object_id: init(_object_id),
            dateStart: init(_dateStart),
            dateEnd: init(_dateEnd),
            energyType : energyType
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            $('.objectName').empty().append(data.result.object.name);
            $('#div_energy').setValues(data.result.datas, '.energyAttr');
            $('#div_energy').setValues(data.result, '.energyAttr');

            var series = []
            // The cost is split into peak hours, off-peak hours and unknown (no HP/HC information) when the plugin knows it
            var costDetail = data.result.datas.costDetail || {};
            var costParts = [
                ['hp', '{{Coût HP}}', '#e74c3c'],
                ['hc', '{{Coût HC}}', '#3498db'],
                ['unknown', '{{Coût inconnu (sans info HP/HC)}}', '#95a5a6']
            ];
            var hasDetail = false;
            costParts.forEach(function (part) {
                if (costDetail[part[0]] && costDetail[part[0]].length > 0) {
                    hasDetail = true;
                }
            });
            (hasDetail ? costParts : [['cost', '{{Coût}}', null]]).forEach(function (part) {
                series.push({
                    step: true,
                    name: part[1] + ' ('+data.result.currency+')',
                    data: hasDetail ? (costDetail[part[0]] || []) : data.result.datas.cost,
                    color: part[2] || undefined,
                    type: 'column',
                    stack : 1,
                    unite : data.result.currency,
                    stacking : 'normal',
                    dataGrouping: {
                        approximation: "sum",
                        enabled: true,
                        forced: true,
                        units: [[groupBy,[1]]]
                    },
                    tooltip: {
                        valueDecimals: 2
                    },
                });
            });
            // Total cost of the day, written above the stacked bar (a line series without line, so it does not stack)
            if (hasDetail) series.push({
                name: '{{Coût total}} ('+data.result.currency+')',
                data: data.result.datas.cost,
                type: 'line',
                color: '#2c3e50',
                lineWidth: 0,
                marker: {enabled: false},
                states: {hover: {lineWidthPlus: 0}},
                enableMouseTracking: true,
                unite : data.result.currency,
                dataGrouping: {
                    approximation: "sum",
                    enabled: true,
                    forced: true,
                    units: [[groupBy,[1]]]
                },
                dataLabels: {
                    enabled: true,
                    crop: false,
                    overflow: 'allow',
                    allowOverlap: true,
                    verticalAlign: 'bottom',
                    y: -4,
                    formatter: function () {
                        return Highcharts.numberFormat(this.y, 2) + ' ' + data.result.currency;
                    },
                    style: {fontWeight: 'bold', textOutline: 'none'}
                },
                tooltip: {
                    valueDecimals: 2
                }
            });
            drawSimpleGraph('div_graphCost',series);
            
            
             if(energyType != 'electricity'){
                var series = []
                if(isset(data.result.datas.totalConsumption)){
                   series.push({
                    name: '{{Consommation total}} ('+data.result.datas.consumptionUnite+')',
                    data:  data.result.datas.totalConsumption,
                    type: 'column',
                    stack : 1,
                    dataGrouping: {
                        approximation: "sum",
                        enabled: true,
                        forced: true,
                        units: [[groupBy,[1]]]
                    },
                    tooltip: {
                        valueDecimals: 2
                    },
                });
               }
               drawSimpleGraph('div_graphDetailConsumption',series);
               return;
           }

           var series = []
           if($('#cb_powerLissage').value() == 1){
            var powerGroup = [['hour',[1]]]
        }else{
            var powerGroup = [['minute',[15]]]
        }
        for(var i in data.result.datas.power){
            if($.isArray(data.result.datas.power[i]) && data.result.datas.power[i].length > 0){
                series.push({
                    step: true,
                    name: i+' (W)',
                    data:  data.result.datas.power[i],
                    unite : 'W',
                    type: 'area',
                    stack : 1,
                    stacking : 'normal',
                    dataGrouping: {
                        approximation: "high",
                        enabled: true,
                        forced: true,
                        units: powerGroup
                    },
                    tooltip: {
                        valueDecimals: 2
                    },
                });
            }
        }
        drawSimpleGraph('div_graphPower',series);

        var series = []
        if(isset(data.result.datas.totalConsumption)){
           totalConsumptionSeries(data.result.datas, {
            step: true,
            name: '{{Consommation totale}} (kWh)',
            type: 'areaspline',
            stack : 2,
            dataGrouping: {
                approximation: "sum",
                enabled: true,
                forced: true,
                units: [[groupBy,[1]]]
            },
            stacking : 'normal',
            tooltip: {
                valueDecimals: 2
            },
        }).forEach(function (totalSerie) { series.push(totalSerie); });
       }
       for(var i in data.result.datas.category){
        if($.isArray(data.result.datas.category[i]) && data.result.datas.category[i].length > 0){
            series.push({
                step: true,
                name: data.result.datas.translation[i]+' (kWh)',
                data:  data.result.datas.category[i],
                type: 'column',
                stack : 1,
                stacking : 'normal',
                dataGrouping: {
                    approximation: "sum",
                    enabled: true,
                    forced: true,
                    units: [[groupBy,[1]]]
                },
                tooltip: {
                    valueDecimals: 2
                },
            });
        }
    }
    drawSimpleGraph('div_graphDetailConsumptionByCategorie',series);


    var series = []
    if(isset(data.result.datas.totalConsumption)){
           totalConsumptionSeries(data.result.datas, {
            step: true,
            name: '{{Consommation totale}} (kWh)',
            type: 'areaspline',
            stack : 2,
        stacking : 'normal',
        dataGrouping: {
            approximation: "sum",
            enabled: true,
            forced: true,
            units: [[groupBy,[1]]]
        },
        tooltip: {
            valueDecimals: 2
        },
    }).forEach(function (totalSerie) { series.push(totalSerie); });
       }
   for(var i in data.result.datas.object){
    if($.isArray(data.result.datas.object[i]) && data.result.datas.object[i].length > 0){
        series.push({
            step: true,
            name: i+' (kWh)',
            data:  data.result.datas.object[i],
            type: 'column',
            stack : 1,
            stacking : 'normal',
            dataGrouping: {
                approximation: "sum",
                enabled: true,
                forced: true,
                units: [[groupBy,[1]]]
            },
            tooltip: {
                valueDecimals: 2
            },
        });
    }
}
drawSimpleGraph('div_graphDetailConsumptionByObject',series);

}
});
}

// The total consumption is one series, but its days do not all have the same origin: the days given by the total
// counter (Linky) are measured, the others are estimates (sum of the equipments). When the plugin tells which days are
// measured, the estimated stretches are drawn in grey (colour zones on the x axis) and the tooltip names the origin.
function totalConsumptionSeries(_datas, _base) {
    var measured = _datas.measuredDays || [];
    if (measured.length == 0) {
        return [$.extend({}, _base, {data: _datas.totalConsumption})];
    }
    var zones = [], previous = null, run = null;
    _datas.totalConsumption.forEach(function (point) {
        var isMeasured = measured.indexOf(point[0]) != -1;
        if (run === null || isMeasured != run) {
            if (run !== null) {
                zones.push({value: point[0], color: run ? '#3498db' : '#95a5a6'});
            }
            run = isMeasured;
        }
    });
    if (run !== null) {
        zones.push({color: run ? '#3498db' : '#95a5a6'});
    }
    return [$.extend({}, _base, {
        data: _datas.totalConsumption,
        zoneAxis: 'x',
        zones: zones,
        tooltip: {
            valueDecimals: 2,
            pointFormatter: function () {
                var origin = measured.indexOf(this.x) != -1 ? '{{mesurée par le compteur}}' : '{{estimée (somme des équipements)}}';
                return '<span style="color:' + this.color + '">●</span> ' + this.series.name + ': <b>' + Highcharts.numberFormat(this.y, 2) + '</b> (' + origin + ')<br/>';
            }
        }
    })];
}

function drawSimpleGraph(_el, _serie) {
    var legend = {
        enabled: true,
        borderColor: 'black',
        borderWidth: 2,
        shadow: true
    };

    new Highcharts.StockChart({
        chart: {
            zoomType: 'x',
            renderTo: _el,
            height: 350,
            spacingTop: 0,
            spacingLeft: 0,
            spacingRight: 0,
            spacingBottom: 0
        },
        credits: {
            text: 'Copyright Jeedom',
            href: 'https://www.jeedom.com',
        },
        navigator: {
            enabled: false
        },
        rangeSelector: {
            buttons: [{
                type: 'week',
                count: 1,
                text: 'S'
            }, {
                type: 'month',
                count: 1,
                text: 'M'
            }, {
                type: 'year',
                count: 1,
                text: 'A'
            }, {
                type: 'all',
                count: 1,
                text: 'Tous'
            }],
            selected: 6,
            inputEnabled: false
        },
        legend: legend,
        tooltip: {
            pointFormat: '<span style="color:{series.color}">{series.name}</span>: <b>{point.y}</b><br/>',
            valueDecimals: 2,
        },
        yAxis: {
            format: '{value}',
            showEmpty: false,
            showLastLabel: true,
            min: 0,
            labels: {
                align: 'right',
                x: -5
            }
        },
        xAxis: {
            type: 'datetime'
        },
        scrollbar: {
            barBackgroundColor: 'gray',
            barBorderRadius: 7,
            barBorderWidth: 0,
            buttonBackgroundColor: 'gray',
            buttonBorderWidth: 0,
            buttonBorderRadius: 7,
            trackBackgroundColor: 'none', trackBorderWidth: 1,
            trackBorderRadius: 8,
            trackBorderColor: '#CCC'
        },
        series: _serie
    });
}

