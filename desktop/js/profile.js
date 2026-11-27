/* Consumption profile of the configuration page (see core/class/energyProfile.class.php). The share of every equipment of the
 * house (the total counter excluded) is typed for two seasons, or computed over a range of characteristic days. The panel
 * replaces the days an equipment measured badly by its share of the total counter; nothing is written in the history. */

var profile = {
    url: 'plugins/energy/core/ajax/energy.ajax.php',
    data: null,
    view: 'year',
    loaded: false
};

profile.plain = function (_message) {
    return $('<div>').html(_message === null || _message === undefined ? '' : String(_message)).text();
};

profile.esc = function (_text) {
    return $('<div>').text(_text === null || _text === undefined ? '' : _text).html();
};

profile.call = function (_data, _success, _error) {
    $.ajax({
        type: 'POST',
        url: profile.url,
        data: _data,
        dataType: 'json',
        // no global ajax events: Jeedom's blurred loading screen is useless for a short read
        global: false,
        error: function (request, status, error) {
            if (_error) {
                _error(profile.plain(error));
            } else {
                handleAjaxError(request, status, error);
            }
        },
        success: function (data) {
            if (data.state != 'ok') {
                if (_error) {
                    _error(profile.plain(data.result));
                } else {
                    $('#div_alert').showAlert({message: profile.plain(data.result), level: 'danger'});
                }
                return;
            }
            _success(data.result);
        }
    });
};

profile.number = function (_value) {
    var n = parseFloat(String(_value).replace(',', '.'));
    return isNaN(n) ? 0 : n;
};

/* ------------------------------------------------------------------ the page */

profile.monthNames = ['{{Janvier}}', '{{Février}}', '{{Mars}}', '{{Avril}}', '{{Mai}}', '{{Juin}}', '{{Juillet}}', '{{Août}}', '{{Septembre}}', '{{Octobre}}', '{{Novembre}}', '{{Décembre}}'];

profile.render = function () {
    var d = profile.data;
    var months = '';
    for (var m = 1; m <= 12; m++) {
        months += '<label class="checkbox-inline"><input type="checkbox" class="profileMonth" value="' + m + '"' + (d.cold.indexOf(m) >= 0 ? ' checked' : '') + '> ' + profile.monthNames[m - 1] + '</label> ';
    }
    $('#div_profileMonths').html(months);
    var rows = '';
    d.equipments.forEach(function (e) {
        var isFixed = d.fixed[e.id] !== undefined;
        rows += '<tr class="profileRow" data-id="' + profile.esc(e.id) + '"><td>' + profile.esc(e.name) + '</td>';
        rows += '<td><select class="form-control input-sm profileKind" style="width:110px"><option value="variable"' + (isFixed ? '' : ' selected') + '>{{Variable}}</option><option value="fixed"' + (isFixed ? ' selected' : '') + '>{{Fixe}}</option></select></td>';
        rows += '<td><input type="number" min="0" step="0.1" class="form-control input-sm profileWatts" style="width:90px" value="' + (isFixed ? profile.esc(d.fixed[e.id]) : '') + '"></td>';
        ['cold', 'hot'].forEach(function (season) {
            var value = d.typed[season][e.id];
            rows += '<td><input type="number" min="0" max="100" step="0.1" class="form-control input-sm profileShare" data-season="' + season + '" style="width:90px" value="' + (value === undefined ? '' : profile.esc(value)) + '"></td>';
        });
        rows += '</tr>';
    });
    if (d.equipments.length == 0) {
        rows = '<tr><td colspan="5">{{Aucun équipement électricité (hors compteur total).}}</td></tr>';
    }
    $('#table_profile tbody').html(rows);
    $('#table_profile tbody tr.profileRow').each(profile.applyKind);
    $('#table_profileRanges tbody').html((d.ranges || []).map(profile.rangeRow).join(''));
    $('#in_profileThreshold').val(d.threshold);
    $('#cb_profileCatchup').prop('checked', d.replaceCatchup == 1);
    profile.totals();
    profile.draw();
};

/* a range of days to recalculate with the profile, both ends included */
profile.rangeRow = function (_r) {
    _r = _r || {};
    return '<tr class="profileRangeRow"><td><input type="date" class="form-control input-sm profileRangeFrom" value="' + profile.esc(_r.from) + '"></td>'
        + '<td><input type="date" class="form-control input-sm profileRangeTo" value="' + profile.esc(_r.to) + '"></td>'
        + '<td><a class="btn btn-danger btn-xs bt_profileRangeRemove"><i class="fas fa-minus-circle"></i></a></td></tr>';
};

/* a fixed equipment has a constant power and no share; a variable one has shares and no power */
profile.applyKind = function () {
    var isFixed = $(this).find('.profileKind').val() == 'fixed';
    $(this).find('.profileWatts').prop('disabled', !isFixed);
    $(this).find('.profileShare').prop('disabled', isFixed);
    if (isFixed) {
        $(this).find('.profileShare').val('');
    }
};

profile.read = function () {
    var cold = [];
    $('.profileMonth:checked').each(function () { cold.push(parseInt($(this).val(), 10)); });
    var shares = {cold: {}, hot: {}};
    var fixed = {};
    $('#table_profile tbody tr.profileRow').each(function () {
        var id = $(this).attr('data-id');
        if ($(this).find('.profileKind').val() == 'fixed') {
            if ($(this).find('.profileWatts').val() !== '') {
                fixed[id] = $(this).find('.profileWatts').val();
            }
            return;
        }
        $(this).find('.profileShare').each(function () {
            var value = $(this).val();
            if (value !== '') {
                shares[$(this).attr('data-season')][id] = value;
            }
        });
    });
    var ranges = [];
    $('#table_profileRanges tbody tr.profileRangeRow').each(function () {
        ranges.push({from: $(this).find('.profileRangeFrom').val(), to: $(this).find('.profileRangeTo').val()});
    });
    return {cold: cold, profile: shares, fixed: fixed, ranges: ranges};
};

/* sum of every season, and what is left unmeasured */
profile.totals = function () {
    var out = '';
    ['cold', 'hot'].forEach(function (season) {
        var sum = 0;
        $('.profileShare[data-season=' + season + ']').each(function () { sum += profile.number($(this).val()); });
        var left = 100 - sum;
        out += '<td' + (left < -0.05 ? ' class="text-danger"' : '') + '>' + (left < -0.05 ? '{{Dépasse 100 %}} : ' + sum.toFixed(1) : '{{Non mesuré}} : ' + left.toFixed(1)) + ' %</td>';
    });
    $('#table_profile tfoot').html('<tr><th>{{Reste de la maison}}</th>' + out + '</tr>');
};

profile.shareOf = function (_season, _id) {
    var d = profile.data;
    return d.profile[_season][_id] === undefined ? 0 : d.profile[_season][_id];
};

/* the doughnut and the list: a season, or the year (each season weighed by the kWh it consumed) */
profile.draw = function () {
    var d = profile.data;
    var names = {};
    d.equipments.forEach(function (e) { names[e.id] = e.name; });
    var parts = [];
    var kwh = 0, cost = 0;
    var seasons = profile.view == 'year' ? ['cold', 'hot'] : [profile.view];
    seasons.forEach(function (season) { kwh += d.seasons[season].kwh; cost += d.seasons[season].cost; });
    // a fixed equipment draws its power every day: its kWh over the days of the period, the variable ones share the rest
    var fixedKwh = 0, days = 0;
    seasons.forEach(function (season) { days += d.seasons[season].days; });
    Object.keys(d.fixed).forEach(function (id) { fixedKwh += d.fixed[id] * 24 / 1000 * days; });
    fixedKwh = Math.min(fixedKwh, kwh);
    var variableKwh = kwh - fixedKwh;
    Object.keys(d.fixed).forEach(function (id) {
        var own = Math.min(d.fixed[id] * 24 / 1000 * days, kwh);
        parts.push({id: id, name: names[id] + ' ({{fixe}})', share: kwh > 0 ? own * 100 / kwh : 0, kwh: own, cost: kwh > 0 ? cost * own / kwh : 0});
    });
    var ids = d.equipments.map(function (e) { return e.id; }).filter(function (id) { return d.fixed[id] === undefined; }).concat(['unmeasured']);
    ids.forEach(function (id) {
        var own = 0;
        seasons.forEach(function (season) {
            var seasonKwh = d.seasons[season].kwh;
            var seasonVariable = Math.max(0, seasonKwh - Math.min(fixedKwh * (kwh > 0 ? seasonKwh / kwh : 0), seasonKwh));
            own += seasonVariable * profile.shareOf(season, id) / 100;
        });
        parts.push({id: id, name: id == 'unmeasured' ? '{{Non mesuré}}' : names[id], share: kwh > 0 ? own * 100 / kwh : 0, kwh: own, cost: kwh > 0 ? cost * own / kwh : 0});
    });
    parts.sort(function (a, b) { return b.share - a.share; });
    var list = '<table class="table table-condensed" style="max-width:520px">';
    parts.forEach(function (p) {
        list += '<tr><td>' + profile.esc(p.name) + '</td><td class="text-right">' + p.share.toFixed(1) + ' %</td><td class="text-right">' + Math.round(p.kwh) + ' kWh</td><td class="text-right">' + p.cost.toFixed(0) + ' €</td></tr>';
    });
    list += '</table><div class="help-block">{{Sur les 12 derniers mois du compteur total}} (' + d.from + ' → ' + d.to + ') : ' + Math.round(kwh) + ' kWh, ' + cost.toFixed(0) + ' €.</div>';
    $('#div_profileList').html(list);
    if (typeof Highcharts === 'undefined') {
        $('#div_profileChart').html('');
        return;
    }
    Highcharts.chart('div_profileChart', {
        chart: {type: 'pie', backgroundColor: 'transparent'},
        title: {text: null},
        credits: {enabled: false},
        plotOptions: {pie: {innerSize: '55%', dataLabels: {enabled: true, format: '{point.name} {point.percentage:.0f} %'}}},
        tooltip: {pointFormat: '{point.y:.1f} %'},
        series: [{name: '{{Profil}}', data: parts.filter(function (p) { return p.share > 0; }).map(function (p) { return {name: p.name, y: p.share}; })}]
    });
};

profile.load = function () {
    profile.call({action: 'profileGet'}, function (result) {
        profile.data = result;
        profile.loaded = true;
        if (!$('#in_profileTo').val()) {
            $('#in_profileTo').val(result.to);
            $('#in_profileFrom').val(result.from);
        }
        profile.render();
    });
};

/* ------------------------------------------------------------------ the buttons */

$('#bt_profileSave').on('click', function () {
    var input = profile.read();
    profile.call({
        action: 'profileSave',
        cold: JSON.stringify(input.cold),
        profile: JSON.stringify(input.profile),
        fixed: JSON.stringify(input.fixed),
        ranges: JSON.stringify(input.ranges),
        threshold: $('#in_profileThreshold').val(),
        replaceCatchup: $('#cb_profileCatchup').is(':checked') ? 1 : 0
    }, function (result) {
        profile.data = result;
        profile.render();
        $('#div_alert').showAlert({message: '{{Profil enregistré.}}', level: 'success'});
    });
});

/* scales the shares of a season down to 100 % when they exceed it */
$('#bt_profileNormalize').on('click', function () {
    ['cold', 'hot'].forEach(function (season) {
        var sum = 0;
        $('.profileShare[data-season=' + season + ']').each(function () { sum += profile.number($(this).val()); });
        if (sum > 100) {
            $('.profileShare[data-season=' + season + ']').each(function () {
                if ($(this).val() !== '') {
                    $(this).val((profile.number($(this).val()) * 100 / sum).toFixed(2));
                }
            });
        }
    });
    profile.totals();
});

$('#table_profile').on('input', '.profileShare', profile.totals);

$('#bt_profileRangeAdd').on('click', function () {
    $('#table_profileRanges tbody').append(profile.rangeRow());
});

$('#table_profileRanges').on('click', '.bt_profileRangeRemove', function () {
    $(this).closest('tr').remove();
});

$('#table_profile').on('change', '.profileKind', function () {
    $(this).closest('tr').each(profile.applyKind);
    profile.totals();
});

$('#div_profileView').on('click', 'a', function () {
    profile.view = $(this).attr('data-view');
    $('#div_profileView a').removeClass('active');
    $(this).addClass('active');
    if (profile.loaded) {
        profile.draw();
    }
});

$('#bt_profileAnalyze').on('click', function () {
    var season = $('#sel_profileSeason').val();
    profile.call({action: 'profileAnalyze', from: $('#in_profileFrom').val(), to: $('#in_profileTo').val()}, function (r) {
        // the result fills the fields: it is a proposal, saved only by "Enregistrer le profil". The kind of every equipment is
        // detected (a constant day = fixed, with its power), and everything stays editable by hand.
        $('#table_profile tbody tr.profileRow').each(function () {
            var id = $(this).attr('data-id');
            if (r.fixed[id] !== undefined) {
                $(this).find('.profileKind').val('fixed');
                $(this).find('.profileWatts').val(r.fixed[id]);
            } else {
                $(this).find('.profileKind').val('variable');
                $(this).find('.profileWatts').val('');
            }
            $(this).each(profile.applyKind);
            if (r.fixed[id] === undefined) {
                $(this).find('.profileShare[data-season=' + season + ']').val(r.shares[id] === undefined ? '' : r.shares[id]);
            }
        });
        profile.totals();
        var html = '<b>' + r.days + '</b> {{jours retenus}}, ' + r.kwh + ' kWh. ' + Object.keys(r.dropped).length + ' {{jours écartés}}';
        if (Object.keys(r.dropped).length > 0) {
            html += ' (' + Object.keys(r.dropped).slice(0, 12).map(function (date) { return profile.esc(date) + ' ' + profile.esc(r.dropped[date]); }).join(', ') + (Object.keys(r.dropped).length > 12 ? ' …' : '') + ')';
        }
        var fixedIds = Object.keys(r.fixed);
        html += '. ' + fixedIds.length + ' {{équipement(s) fixe(s) détecté(s)}}' + (fixedIds.length ? ' (' + fixedIds.map(function (id) { return r.fixed[id] + ' W'; }).join(', ') + ', ' + r.fixedKwh + ' kWh)' : '');
        html += '. {{Non mesuré}} : ' + r.unmeasured + ' %. {{Les types et les champs de la saison choisie sont remplis, modifiables à la main : enregistrez pour les appliquer.}}';
        $('#div_profileAnalysis').html(html);
    }, function (error) {
        $('#div_profileAnalysis').html('<span class="text-danger">' + profile.esc(error) + '</span>');
    });
});

$('#bt_profileDays').on('click', function () {
    var d = profile.data;
    // the whole available history: a year before today is what the page already weighs
    var from = $('#in_profileFrom').val() || d.from, to = $('#in_profileTo').val() || d.to;
    profile.call({action: 'profileDays', from: from, to: to}, function (r) {
        var html = '<b>' + r.total + '</b> {{jours à recalculer}} (' + from + ' → ' + to + ') : ' + r.byReason.aberrant + ' {{aberrants}}, ' + r.byReason.frozen + ' {{figés}}, ' + r.byReason.missing + ' {{absents}}, ' + r.byReason.manual + ' {{saisis}}.';
        html += '<table class="table table-condensed" style="max-width:700px"><tbody>';
        r.equipments.forEach(function (e) {
            if (e.aberrant.length + e.frozen + e.missing + e.manual == 0) {
                return;
            }
            html += '<tr><td>' + profile.esc(e.name) + '</td><td>' + e.aberrant.length + ' {{aberrants}}' + (e.aberrant.length ? ' (' + e.aberrant.slice(0, 8).join(', ') + (e.aberrant.length > 8 ? ' …' : '') + ')' : '') + '</td><td>' + e.frozen + ' {{figés}}</td><td>' + e.missing + ' {{absents}}</td><td>' + e.manual + ' {{saisis}}</td></tr>';
        });
        html += '</tbody></table>';
        $('#div_profileDays').html(html);
    }, function (error) {
        $('#div_profileDays').html('<span class="text-danger">' + profile.esc(error) + '</span>');
    });
});

profile.load();
