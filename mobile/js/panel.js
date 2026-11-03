
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

 function initEnergyPanel(_options) {
    _options = init(_options, '');
    var options = _options.split("::");

    var object_id = options[0];
    if (!isset(options[1])) {
        var energyType = 'electricity';
    } else {
        var energyType = options[1];
    }
    if (!isset(options[2])) {
        var groupBy = 'day';
    } else {
        var groupBy = options[2];
    }
    $('.link[data-plugin=energy][data-value=electricity]').attr('data-option', object_id + '::electricity::'+groupBy);
    $('.link[data-plugin=energy][data-value=water]').attr('data-option', object_id + '::water::'+groupBy);
    $('.link[data-plugin=energy][data-value=gas]').attr('data-option', object_id + '::gas::'+groupBy);
    $('.link[data-plugin=energy][data-value=day]').attr('data-option', object_id + '::'+energyType+'::day');
    $('.link[data-plugin=energy][data-value=month]').attr('data-option', object_id + '::'+energyType+'::month');

    $('.link[data-plugin=energy][data-value=' + energyType + ']').addClass('ui-btn-raised clr-primary');

    $('.link[data-plugin=energy][data-value=' + groupBy + ']').addClass('ui-btn-raised clr-primary');

    jeedom.object.all({
        error: function (error) {
            $('#div_alert').showAlert({message: error.message, level: 'danger'});
        },
        success: function (objects) {
            var li = ' <ul data-role="listview">';
            for (var i in objects) {
                if (objects[i].isVisible == 1) {
                    var icon = '';
                    if (isset(objects[i].display) && isset(objects[i].display.icon)) {
                        icon = objects[i].display.icon;
                    }
                    li += '<li><a href="#" class="link" data-page="panel" data-plugin="energy" data-title="' + icon.replace(/\"/g, "\'") + ' ' + objects[i].name + '" data-option="' + objects[i].id + '::' + energyType + '"><span>' + icon + '</span> ' + objects[i].name + '</a></li>';
                }
            }
            li += '</ul>';
            panel(li);
        }
    });
    setWidthChartContainer();
    displayEnergy(object_id, energyType,groupBy); 



    $('#bt_valideDate').on('click', function () {
        jeedom.history.chart = [];
        displayEnergy(object_id, energyType,groupBy, $('#in_dateStart').val(), $('#in_dateEnd').val());
    });

    $(window).on("orientationchange", function (event) {
        setWidthChartContainer();
    });
}

function displayEnergy(object_id, _energyType,_groupBy, _dateStart, _dateEnd) {
    $.showLoading();
    $.ajax({
        type: 'POST',
        url: 'plugins/energy/core/ajax/energy.ajax.php',
        data: {
            action: 'getData',
            object_id: object_id,
            dateStart: init(_dateStart),
            dateEnd: init(_dateEnd),
            energyType :  _energyType,
            groupBy :  _groupBy
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
            $('#div_energyPackbery').packery('destroy');
            $('#in_dateStart').value(data.result.date.start);
            $('#in_dateEnd').value(data.result.date.end);
            $('.span_objectName').empty().append(data.result.object.name);

            $('.objectName').empty().append(data.result.object.name);
            $('#div_energy').setValues(data.result.datas, '.energyAttr');
            $('#div_energy').setValues(data.result, '.energyAttr');

            if(data.result.enableElectricity == 0){
                $('.link[data-value=electricity]').hide();
            }
            if(data.result.enableWater == 0){
                $('.link[data-value=water]').hide();
            }
            if(data.result.enableGas == 0){
                $('.link[data-value=gas]').hide();
            }

            var series = []
            series.push({
                step: true,
                name: '{{Coût}}',
                data:  data.result.datas.cost,
                type: 'column',
                dataGrouping: {
                    approximation: "sum",
                    enabled: true,
                    forced: true,
                    units: [[_groupBy,[1]]]
                },
                tooltip: {
                    valueDecimals: 2
                },
            });
            drawSimpleGraph('div_graphCost',series);

            var series = []
            if( _energyType != 'electricity'){
                if(isset(data.result.datas.totalConsumption)){
                 series.push({
                    step: true,
                    name: '{{Consommation total}}',
                    data:  data.result.datas.totalConsumption,
                    type: 'column',
                    dataGrouping: {
                        approximation: "sum",
                        enabled: true,
                        forced: true,
                        units: [[_groupBy,[1]]]
                    },
                    tooltip: {
                        valueDecimals: 2
                    },
                });
             }
             drawSimpleGraph('div_graphDetailConsumption',series);
             $('.energyAttr[data-l1key=total][data-l2key=power]').parent().hide();
             $('#div_graphDetailConsumptionByObject').parent().hide();
             $('#div_graphDetailConsumptionByCategorie').parent().hide();
             $('#div_graphDetailConsumptionByObjectByMonth').parent().hide();
             $('#div_graphDetailConsumptionByCategorieByMonth').parent().hide();
             $('#div_graphPowerByMonth').parent().hide();
             $('#div_graphPower').parent().hide();
             $('#div_energyPackbery').packery();
             return;

         }
         $('#div_graphDetailConsumption').parent().hide();
         $('#div_graphDetailConsumptionByMonth').parent().hide();

         var series = []
         for(var i in data.result.datas.power){
            if($.isArray(data.result.datas.power[i]) && data.result.datas.power[i].length > 0){
                series.push({
                    step: true,
                    name: i,
                    data:  data.result.datas.power[i],
                    type: 'area',
                    stack : 1,
                    pointInterval: 3600 * 1000,
                    stacking : 'normal',
                    dataGrouping: {
                        approximation: "high",
                        enabled: true,
                        forced: true,
                        units: [['hour',[1]]]
                    },
                    tooltip: {
                        valueDecimals: 2
                    },
                });
            }
        }
        drawSimpleGraph('div_graphPower',series);

        var series = []
        if(isset( data.result.datas.totalConsumption)){
           series.push({
            step: true,
            name: '{{Consommation total}}',
            data:  data.result.datas.totalConsumption,
            type: 'area',
            stack : 2,
            stacking : 'normal',
            dataGrouping: {
                approximation: "sum",
                enabled: true,
                forced: true,
                units: [[_groupBy,[1]]]
            },
            tooltip: {
                valueDecimals: 2
            },
        });
       }
       for(var i in data.result.datas.category){
        series.push({
            step: true,
            name: data.result.datas.translation[i],
            data:  data.result.datas.category[i],
            type: 'column',
            stack : 1,
            stacking : 'normal',
            dataGrouping: {
                approximation: "sum",
                enabled: true,
                forced: true,
                units: [[_groupBy,[1]]]
            },
            tooltip: {
                valueDecimals: 2
            },
        });
    }
    drawSimpleGraph('div_graphDetailConsumptionByCategorie',series);
    var series = []
    if(isset(data.result.datas.totalConsumption)){
     series.push({
        step: true,
        name: '{{Consommation total}}',
        data:  data.result.datas.totalConsumption,
        type: 'area',
        stack : 2,
        stacking : 'normal',
        dataGrouping: {
            approximation: "sum",
            enabled: true,
            forced: true,
            units: [[_groupBy,[1]]]
        },
        tooltip: {
            valueDecimals: 2
        },
    });
 }
 for(var i in data.result.datas.object){
    series.push({
        step: true,
        name: i,
        data:  data.result.datas.object[i],
        type: 'column',
        stack : 1,
        stacking : 'normal',
        dataGrouping: {
            approximation: "sum",
            enabled: true,
            forced: true,
            units: [[_groupBy,[1]]]
        },
        tooltip: {
            valueDecimals: 2
        },
    });
}
drawSimpleGraph('div_graphDetailConsumptionByObject',series);

$('#div_energyPackbery').packery();
$.hideLoading();
}
});
}

function setWidthChartContainer() {
    if (deviceInfo.type == 'phone') {
        $('.chartContainer').width((deviceInfo.width - 50));
    } else {
        $('.chartContainer').width(((deviceInfo.width / 2) - 50));
    }
    $('.chartContainer').css('margin-left', '20px');
    $('#div_energyPackbery').packery();
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
            href: 'http://jeedom.fr',
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