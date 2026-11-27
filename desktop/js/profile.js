/* Consumption profiles of the configuration page (see plugin_info/configuration_updateWithProfil.php, core/class/energyProfile.class.php
 * and energyProfileUpdate.class.php). Two profiles, one per season: every equipment of the house (the total counter excluded) is either
 * FIXED (a value in kWh per day) or VARIABLE (a share in % of what the fixed ones leave of the total of the day). They are typed, or
 * computed over a range of characteristic days, then the button "Recalculer les consommations" opens a modal that rewrites the history
 * of the equipments with them (analysis, backup, writing, verification). */

var profile = {
    url: 'plugins/energy/core/ajax/energy.ajax.php',
    data: null,
    view: 'year',
    loaded: false,
    running: false,
    box: null,
    steps: {
        check: '{{Contrôle des paramètres}}',
        backup: '{{Sauvegarde}}',
        write: '{{Écriture}}',
        verify: '{{Vérification}}'
    }
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
        // no global ajax events: Jeedom's blurred loading screen would hide the progress of the recalculation
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

/* a fixed equipment has a value in kWh per day and no share; a variable one has shares and no value */
profile.applyKind = function () {
    var isFixed = $(this).find('.profileKind').val() == 'fixed';
    $(this).find('.profileKwh').prop('disabled', !isFixed);
    $(this).find('.profileShare').prop('disabled', isFixed);
    if (isFixed) {
        $(this).find('.profileShare').val('');
    }
};

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
        rows += '<td><input type="number" min="0" step="0.01" class="form-control input-sm profileKwh" style="width:100px" value="' + (isFixed ? profile.esc(d.fixed[e.id]) : '') + '"></td>';
        ['cold', 'hot'].forEach(function (season) {
            var value = d.typed[season][e.id];
            rows += '<td><input type="number" min="0" max="100" step="0.1" class="form-control input-sm profileShare" data-season="' + season + '" style="width:90px" value="' + (value === undefined ? '' : profile.esc(value)) + '"></td>';
        });
        // the share of the consumption that falls in the off-peak hours (HC): the rest is HP. Only with an HP/HC tariff.
        ['cold', 'hot'].forEach(function (season) {
            var value = d.hc[season][e.id];
            rows += '<td class="profileHcColumn"><input type="number" min="0" max="100" step="0.1" class="form-control input-sm profileHc" data-season="' + season + '" style="width:90px" value="' + (value === undefined ? '' : profile.esc(value)) + '"></td>';
        });
        rows += '</tr>';
    });
    if (d.equipments.length == 0) {
        rows = '<tr><td colspan="7">{{Aucun équipement électricité (hors compteur total).}}</td></tr>';
    }
    $('#table_profile tbody').html(rows);
    // with a fixed tariff there is no split in HC and HP: the columns are hidden
    $('.profileHcColumn').toggle(!!d.variableRate);
    $('#table_profile tbody tr.profileRow').each(profile.applyKind);
    $('#in_profileToleranceFixed').val(d.toleranceFixed);
    $('#in_profileToleranceVariable').val(d.toleranceVariable);
    $('#ta_profileExcluded').val(d.excluded || '');
    profile.excludedPreview();
    profile.totals();
    profile.draw();
};

/* reads the list of days to leave out like the server does, to show what is understood while typing (the server decides) */
profile.parseExcluded = function (text) {
    text = $.trim(text);
    var out = {error: null, ranges: [], days: 0};
    if (text === '') {
        return out;
    }
    var rest = $.trim(text.replace(/\[[^\[\]]*\]/g, '').replace(/[,;\s]/g, ''));
    if (rest !== '') {
        out.error = '{{texte hors crochets non compris}} : ' + rest;
        return out;
    }
    var date = '(\\d{4}-\\d{2}-\\d{2}|\\d{2}\\/\\d{2}\\/\\d{4})';
    var re = new RegExp('^\\s*' + date + '(?:\\s*-\\s*' + date + ')?\\s*$');
    var iso = function (d) {
        var v = d.indexOf('/') >= 0 ? d.substr(6, 4) + '-' + d.substr(3, 2) + '-' + d.substr(0, 2) : d;
        var t = new Date(v + 'T12:00:00Z');
        return isNaN(t.getTime()) || t.toISOString().substr(0, 10) !== v ? null : v;
    };
    var items = text.match(/\[[^\[\]]*\]/g) || [];
    for (var i = 0; i < items.length; i++) {
        var m = re.exec(items[i].slice(1, -1));
        if (!m) {
            out.error = '{{élément non compris}} : ' + items[i];
            return out;
        }
        var from = iso(m[1]), to = m[2] ? iso(m[2]) : from;
        if (from === null || to === null) {
            out.error = '{{date impossible}} : ' + items[i];
            return out;
        }
        if (from > to) {
            out.error = '{{la fin est avant le début}} : ' + items[i];
            return out;
        }
        out.ranges.push({from: from, to: to});
        out.days += Math.round((new Date(to + 'T12:00:00Z') - new Date(from + 'T12:00:00Z')) / 86400000) + 1;
    }
    return out;
};

profile.excludedPreview = function () {
    var r = profile.parseExcluded($('#ta_profileExcluded').val());
    var box = $('#div_profileExcludedPreview');
    if (r.error) {
        box.html('<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> ' + profile.esc(r.error) + '</span>');
    } else if (r.ranges.length == 0) {
        box.text('{{Aucun jour écarté.}}');
    } else {
        box.html(r.ranges.length + ' {{élément(s)}}, ' + r.days + ' {{jour(s)}} : ' + r.ranges.map(function (x) { return profile.esc(x.from == x.to ? x.from : x.from + ' → ' + x.to); }).join(', '));
    }
};

$('#ta_profileExcluded').on('input', function () { profile.excludedPreview(); });

profile.read = function () {
    var cold = [];
    $('.profileMonth:checked').each(function () { cold.push(parseInt($(this).val(), 10)); });
    var shares = {cold: {}, hot: {}};
    var fixed = {};
    $('#table_profile tbody tr.profileRow').each(function () {
        var id = $(this).attr('data-id');
        if ($(this).find('.profileKind').val() == 'fixed') {
            // a fixed equipment is always sent, even with no value: the server refuses it with a message instead of losing it
            fixed[id] = $(this).find('.profileKwh').val();
            return;
        }
        $(this).find('.profileShare').each(function () {
            var value = $(this).val();
            if (value !== '') {
                shares[$(this).attr('data-season')][id] = value;
            }
        });
    });
    var hc = {cold: {}, hot: {}};
    $('#table_profile tbody tr.profileRow').each(function () {
        var id = $(this).attr('data-id');
        $(this).find('.profileHc').each(function () {
            if ($(this).val() !== '') {
                hc[$(this).attr('data-season')][id] = $(this).val();
            }
        });
    });
    return {cold: cold, profile: shares, fixed: fixed, hc: hc};
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
    $('#table_profile tfoot').html('<tr><th colspan="3">{{Reste de la maison}}</th>' + out + '<td class="profileHcColumn" colspan="2"></td></tr>');
    $('.profileHcColumn').toggle(!!(profile.data && profile.data.variableRate));
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
    var kwh = 0, cost = 0, days = 0;
    var seasons = profile.view == 'year' ? ['cold', 'hot'] : [profile.view];
    seasons.forEach(function (season) { kwh += d.seasons[season].kwh; cost += d.seasons[season].cost; days += d.seasons[season].days; });
    // a fixed equipment consumes its value every day: its kWh over the days of the period, the variable ones share the rest
    var fixedIds = Object.keys(d.fixed);
    var fixedKwh = 0;
    fixedIds.forEach(function (id) { fixedKwh += d.fixed[id] * days; });
    var scale = fixedKwh > kwh && fixedKwh > 0 ? kwh / fixedKwh : 1;
    fixedIds.forEach(function (id) {
        var own = d.fixed[id] * days * scale;
        parts.push({id: id, name: names[id] + ' ({{fixe}})', share: kwh > 0 ? own * 100 / kwh : 0, kwh: own, cost: kwh > 0 ? cost * own / kwh : 0});
    });
    var ids = d.equipments.map(function (e) { return e.id; }).filter(function (id) { return d.fixed[id] === undefined; }).concat(['unmeasured']);
    ids.forEach(function (id) {
        var own = 0;
        seasons.forEach(function (season) {
            var seasonKwh = d.seasons[season].kwh;
            var seasonFixed = Math.min(fixedIds.reduce(function (s, f) { return s + d.fixed[f] * d.seasons[season].days; }, 0), seasonKwh);
            own += Math.max(0, seasonKwh - seasonFixed) * profile.shareOf(season, id) / 100;
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
        hc: JSON.stringify(input.hc),
        toleranceFixed: $('#in_profileToleranceFixed').val(),
        toleranceVariable: $('#in_profileToleranceVariable').val(),
        excluded: $('#ta_profileExcluded').val()
    }, function (result) {
        profile.data = result;
        profile.render();
        $('#div_alert').showAlert({message: '{{Profils enregistrés.}}', level: 'success'});
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
        // the result fills the table: it is a proposal, saved only by "Enregistrer les profils". The kind of every equipment is detected
        // (a constant day = fixed, with its kWh per day), and everything stays editable by hand.
        $('#table_profile tbody tr.profileRow').each(function () {
            var id = $(this).attr('data-id');
            if (r.fixed[id] !== undefined) {
                $(this).find('.profileKind').val('fixed');
                $(this).find('.profileKwh').val(r.fixed[id]);
            } else {
                $(this).find('.profileKind').val('variable');
                $(this).find('.profileKwh').val('');
            }
            $(this).each(profile.applyKind);
            if (r.fixed[id] === undefined) {
                $(this).find('.profileShare[data-season=' + season + ']').val(r.shares[id] === undefined ? '' : r.shares[id]);
            }
            // the share of HC deduced from the HC and HP history of the equipment, for the season chosen
            $(this).find('.profileHc[data-season=' + season + ']').val(r.hc[id] === undefined ? '' : r.hc[id]);
        });
        profile.totals();
        var dropped = Object.keys(r.dropped);
        var byHand = dropped.filter(function (date) { return r.dropped[date] == 'excluded'; }).length;
        var html = '<b>' + r.days + '</b> {{jours retenus}}, ' + r.kwh + ' kWh. ' + dropped.length + ' {{jours écartés}}' + (byHand ? ' (' + byHand + ' {{écartés à la main}})' : '');
        if (dropped.length > 0) {
            html += ' (' + dropped.slice(0, 12).map(function (date) { return profile.esc(date); }).join(', ') + (dropped.length > 12 ? ' …' : '') + ')';
        }
        var fixedIds = Object.keys(r.fixed);
        html += '. ' + fixedIds.length + ' {{équipement(s) fixe(s) détecté(s)}}' + (fixedIds.length ? ' (' + fixedIds.map(function (id) { return r.fixed[id] + ' kWh/{{jour}}'; }).join(', ') + ')' : '');
        var hcIds = Object.keys(r.hc);
        html += '. ' + hcIds.length + ' {{équipement(s) avec une part d\'heures creuses déduite}}';
        html += '. {{Non mesuré}} : ' + r.unmeasured + ' %. {{Les types, les valeurs, les parts et les heures creuses de la saison choisie sont remplis, modifiables à la main : enregistrez pour les appliquer.}}';
        $('#div_profileAnalysis').html(html);
    }, function (error) {
        $('#div_profileAnalysis').html('<span class="text-danger">' + profile.esc(error) + '</span>');
    });
});

/* ------------------------------------------------------------------ the recalculation of the consumptions (modal) */

profile.copy = function (_selector) {
    $(_selector).focus().select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    $('#div_profileUpdateAlert').html('<div class="alert alert-' + (ok ? 'success' : 'warning') + '">' + (ok ? '{{Détail copié dans le presse-papiers.}}' : '{{Copie impossible : sélectionnez le texte (Ctrl+C).}}') + '</div>');
};

/* The buttons of the footer, found by their class (btn-default: close, btn-success: apply) and not by an attribute of the dialog,
 * which depends on the version of bootbox. */
profile.footer = function () {
    var footer = profile.box.find('.modal-footer');
    return {apply: footer.find('.btn-success'), close: footer.find('.btn-default')};
};

profile.options = function () {
    return {
        blank: $('#cb_profileBlank').is(':checked') ? 1 : 0,
        aberrant: $('#cb_profileAberrant').is(':checked') ? 1 : 0,
        filled: $('#cb_profileFilled').is(':checked') ? 1 : 0
    };
};

profile.progress = function (_states) {
    var names = Object.keys(profile.steps);
    var done = 0;
    var html = '<ul class="list-unstyled">';
    names.forEach(function (name) {
        var state = _states[name] || 'wait';
        if (state == 'ok') { done++; }
        var icon = {wait: 'fa-circle text-muted', run: 'fa-spinner fa-spin text-primary', ok: 'fa-check-circle text-success', error: 'fa-times-circle text-danger'}[state];
        html += '<li><i class="fas ' + icon + '"></i> ' + profile.esc(profile.steps[name]) + '</li>';
    });
    var percent = Math.round(100 * done / names.length);
    return '<div class="progress"><div class="progress-bar' + (_states.failed ? ' progress-bar-danger' : '') + '" style="width:' + percent + '%">' + percent + ' %</div></div>' + html + '</ul>';
};

/* the analysis: nothing is written, the result says what each equipment holds and what the profile gives it */
profile.analyse = function () {
    var from = $('#in_profileUpdateFrom').val(), to = $('#in_profileUpdateTo').val();
    $('#div_profileUpdateBody').html('<p><i class="fas fa-spinner fa-spin"></i> {{Analyse en cours…}}</p>');
    $('#div_profileUpdateAlert').empty();
    profile.footer().apply.prop('disabled', true);
    profile.call({action: 'profilePreview', from: from, to: to, options: JSON.stringify(profile.options())}, function (r) {
        var s = r.summary;
        var html = '<div class="alert alert-info">' + s.from + ' → ' + s.to + ' · ' + s.days + ' {{jours avec un total}}, ' + s.noTotal + ' {{sans total (ignorés)}}, ' + s.excluded + ' {{écartés (saisie)}}<br>'
            + s.write + ' {{équipement(s)-jour(s) réécrits}}, ' + s.keep + ' {{conservés}} ({{vierges}} ' + s.blank + ', {{aberrants}} ' + s.aberrant + ', {{renseignés}} ' + s.filled + ')<br>'
            + '{{Consommation réécrite}} : <strong>' + s.before + '</strong> kWh → <strong>' + s.after + '</strong> kWh</div>';
        html += '<h4>{{Détail de l\'analyse}} <a class="btn btn-default btn-xs" id="bt_profileCopyPreview"><i class="fas fa-copy"></i> {{Copier}}</a></h4><textarea id="ta_profilePreview" class="form-control" rows="10" readonly style="font-family:monospace;font-size:11px"></textarea>';
        $('#div_profileUpdateBody').html(html);
        $('#ta_profilePreview').val(r.text);
        $('#bt_profileCopyPreview').on('click', function () { profile.copy('#ta_profilePreview'); });
        profile.footer().apply.prop('disabled', s.write == 0);
    }, function (error) {
        $('#div_profileUpdateBody').empty();
        $('#div_profileUpdateAlert').html('<div class="alert alert-danger">' + profile.esc(error) + '</div>');
    });
};

profile.apply = function () {
    profile.running = true;
    var footer = profile.footer();
    footer.apply.hide();
    footer.close.prop('disabled', true);
    var from = $('#in_profileUpdateFrom').val(), to = $('#in_profileUpdateTo').val();
    var options = JSON.stringify(profile.options());
    $('#div_profileUpdateForm').hide();
    var states = {};
    var id = '';
    var show = function () { $('#div_profileUpdateBody').html(profile.progress(states)); };
    var finish = function (_result, _ok) {
        profile.running = false;
        var html = profile.progress(states) + '<div class="alert alert-' + (_ok ? 'success' : 'danger') + '"><strong>' + (_ok ? '{{Recalcul terminé sans erreur.}}' : '{{Recalcul interrompu : restaurez la sauvegarde si besoin.}}') + '</strong><br>' + profile.esc(_result.message) + (_result.backup ? '<br>{{Sauvegarde}} : ' + profile.esc(_result.backup) : '') + '</div>';
        html += '<h4>{{Rapport}} <a class="btn btn-default btn-xs" id="bt_profileCopyResult"><i class="fas fa-copy"></i> {{Copier}}</a></h4><textarea id="ta_profileResult" class="form-control" rows="10" readonly style="font-family:monospace;font-size:11px"></textarea>';
        $('#div_profileUpdateBody').html(html);
        $('#ta_profileResult').val(_result.text);
        $('#bt_profileCopyResult').on('click', function () { profile.copy('#ta_profileResult'); });
        // everything went well: the only button left is "Fermer"; after an error, "Annuler" stays
        footer.apply.hide();
        footer.close.prop('disabled', false).text(_ok ? '{{Fermer}}' : '{{Annuler}}').show();
    };
    var run = function (_step) {
        states[_step] = 'run';
        show();
        profile.call({action: 'profileStep', id: id, step: _step, from: from, to: to, options: options}, function (result) {
            id = result.id;
            if (!result.ok) {
                states[_step] = 'error';
                states.failed = true;
                finish(result, false);
                return;
            }
            states[_step] = 'ok';
            show();
            if (result.done) {
                finish(result, true);
            } else {
                run(result.next);
            }
        }, function (error) {
            states[_step] = 'error';
            states.failed = true;
            finish({message: error, text: error, backup: null}, false);
        });
    };
    run('check');
};

profile.open = function () {
    profile.running = false;
    var yesterday = profile.data && profile.data.to ? profile.data.to : '';
    var form = '<div id="div_profileUpdateForm" class="form-inline">'
        + '<p><input class="form-control input-sm" id="in_profileUpdateFrom" type="date" value="' + profile.esc($('#in_profileFrom').val()) + '"/> → '
        + '<input class="form-control input-sm" id="in_profileUpdateTo" type="date" value="' + profile.esc(yesterday) + '"/></p>'
        + '<p>{{Jours réécrits}} : '
        + '<label class="checkbox-inline"><input type="checkbox" id="cb_profileBlank" checked/> {{vierges}}</label> '
        + '<label class="checkbox-inline"><input type="checkbox" id="cb_profileAberrant" checked/> {{aberrants}}</label> '
        + '<label class="checkbox-inline"><input type="checkbox" id="cb_profileFilled"/> {{déjà renseignés}}</label> '
        + '<a class="btn btn-default btn-xs" id="bt_profileUpdateAnalyse"><i class="fas fa-search"></i> {{Analyser}}</a></p>'
        + '</div>';
    profile.box = bootbox.dialog({
        title: '<i class="fas fa-sync"></i> {{Recalcul des consommations avec les profils}}',
        size: 'large',
        closeButton: false,
        message: '<div id="div_profileUpdate">' + form + '<div id="div_profileUpdateAlert"></div><div id="div_profileUpdateBody"><p class="text-muted">{{Choisissez la plage et les jours à réécrire, puis lancez l\'analyse : rien n\'est écrit avant « Appliquer ».}}</p></div></div>',
        buttons: {
            cancel: {label: '{{Annuler}}', className: 'btn-default', callback: function () { return !profile.running; }},
            apply: {label: '{{Appliquer}}', className: 'btn-success', callback: function () { profile.apply(); return false; }}
        }
    });
    profile.footer().apply.prop('disabled', true);
    $('#bt_profileUpdateAnalyse').on('click', profile.analyse);
    // a change of the range or of the boxes invalidates the analysis: Appliquer waits for a new one
    $('#div_profileUpdateForm').on('change', 'input', function () { profile.footer().apply.prop('disabled', true); });
};

$('#bt_profileUpdate').on('click', function () {
    if (!profile.loaded) {
        return;
    }
    profile.open();
});

profile.load();
