/* Tariff periods of the configuration page, and the cost catch-up of one period (see core/class/energyRates.class.php and
 * energyRateCatchup.class.php). The periods are typed in a table; "Sauvegarder" of the page saves them (the setting
 * ratePeriods holds them as JSON), and the button of a saved period recomputes the stored cost of its days. */

var rates = {
    url: 'plugins/energy/core/ajax/energy.ajax.php',
    targets: [],
    steps: {},
    saved: [],
    running: false,
    done: false,
    box: null
};

rates.esc = function (_text) {
    return $('<div>').text(_text === null || _text === undefined ? '' : _text).html();
};

rates.call = function (_data, _success, _error) {
    $.ajax({
        type: 'POST',
        url: rates.url,
        data: _data,
        dataType: 'json',
        // no global ajax events: Jeedom's blurred loading screen would hide the progress of the recalculation
        global: false,
        error: function (request, status, error) {
            if (_error) {
                _error(error);
            } else {
                handleAjaxError(request, status, error);
            }
        },
        success: function (data) {
            if (data.state != 'ok') {
                if (_error) {
                    _error(data.result);
                } else {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                }
                return;
            }
            _success(data.result);
        }
    });
};

rates.addDay = function (_date, _n) {
    var d = new Date(_date + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + _n);
    return d.toISOString().substr(0, 10);
};

/* ------------------------------------------------------------------ the table */

rates.fields = ['rateHp', 'rateHc', 'rate', 'subscriptionDaily'];
rates.hours = ['startHc', 'endHc', 'startHc2', 'endHc2', 'startHc3', 'endHc3'];

rates.row = function (_p) {
    _p = _p || {};
    var input = function (key, width, placeholder) {
        return '<input type="text" class="form-control input-sm ratePeriod ' + key + '" data-key="' + key + '" style="width:' + width + '" placeholder="' + (placeholder || '') + '" value="' + rates.esc(_p[key]) + '">';
    };
    var html = '<tr class="ratePeriodRow">';
    html += '<td><input type="date" class="form-control input-sm ratePeriod from" data-key="from" value="' + rates.esc(_p.from) + '"></td>';
    html += '<td><input type="date" class="form-control input-sm ratePeriod to" data-key="to" value="' + rates.esc(_p.to) + '" placeholder="{{en cours}}"></td>';
    html += '<td>' + input('rateHp', '80px') + '</td><td>' + input('rateHc', '80px') + '</td><td>' + input('rate', '80px') + '</td><td>' + input('subscriptionDaily', '80px') + '</td>';
    html += '<td><div class="ratePeriodHours" style="white-space:nowrap">';
    [['startHc', 'endHc'], ['startHc2', 'endHc2'], ['startHc3', 'endHc3']].forEach(function (pair) {
        html += '<span class="ratePeriodPair">' + input(pair[0], '55px', 'HH:MM') + ' → ' + input(pair[1], '55px', 'HH:MM') + '</span> ';
    });
    html += '</div></td>';
    html += '<td style="white-space:nowrap"><a class="btn btn-warning btn-xs bt_rateCatchup"><i class="fas fa-calculator"></i> {{Recalculer les coûts}}</a> <a class="btn btn-danger btn-xs bt_rateRemove"><i class="fas fa-minus-circle"></i></a></td>';
    html += '</tr>';
    return html;
};

rates.render = function (_periods) {
    $('#table_ratePeriods tbody').empty();
    (_periods || []).forEach(function (p) {
        $('#table_ratePeriods tbody').append(rates.row(p));
    });
    rates.refreshButtons();
};

rates.read = function () {
    var periods = [];
    $('#table_ratePeriods tbody tr').each(function () {
        var p = {};
        $(this).find('.ratePeriod').each(function () {
            p[$(this).attr('data-key')] = $(this).val();
        });
        periods.push(p);
    });
    return periods;
};

/* a button only works on what is saved: the calculation reads the saved periods */
rates.refreshButtons = function () {
    var saved = rates.saved.map(function (p) { return JSON.stringify(rates.normalized(p)); });
    $('#table_ratePeriods tbody tr').each(function () {
        var p = {};
        $(this).find('.ratePeriod').each(function () { p[$(this).attr('data-key')] = $(this).val(); });
        var same = saved.indexOf(JSON.stringify(rates.normalized(p))) !== -1;
        $(this).find('.bt_rateCatchup').toggleClass('disabled', !same).attr('title', same ? '' : '{{Sauvegardez d\'abord la configuration}}');
    });
};

rates.normalized = function (_p) {
    var out = {};
    ['from', 'to'].concat(rates.fields).concat(rates.hours).forEach(function (key) {
        out[key] = _p[key] === null || _p[key] === undefined ? '' : String(_p[key]).trim();
    });
    return out;
};

/* a new period starts where the previous one ends, with its values: only what changes has to be typed */
rates.add = function () {
    var rows = rates.read();
    var last = rows.length ? rows[rows.length - 1] : {};
    var p = rates.normalized(last);
    p.from = last.to ? last.to : (last.from ? rates.addDay(last.from, 1) : '');
    p.to = '';
    $('#table_ratePeriods tbody').append(rates.row(p));
    rates.refreshButtons();
};

rates.load = function () {
    rates.call({action: 'catchupRatePeriods'}, function (result) {
        rates.saved = result.periods;
        rates.targets = result.targets;
        rates.steps = result.steps;
        rates.render(result.periods);
    }, function () {});
};

/* the page is saved by Jeedom with its own button: the periods are validated and saved first, in the same click */
rates.saveBeforePage = function () {
    return new Promise(function (resolve, reject) {
        rates.call({action: 'catchupRateSave', periods: JSON.stringify(rates.read())}, function (periods) {
            rates.saved = periods;
            rates.refreshButtons();
            resolve();
        }, function (error) {
            $('#div_alert').showAlert({message: error, level: 'danger'});
            reject(error);
        });
    });
};

$(document).on('click', '#bt_rateAdd', rates.add);
$(document).on('click', '.bt_rateRemove', function () {
    $(this).closest('tr').remove();
    rates.refreshButtons();
});
$(document).on('input change', '.ratePeriod', rates.refreshButtons);
$(document).on('click', '#bt_rateSave', function () {
    rates.saveBeforePage().then(function () {
        $('#div_alert').showAlert({message: '{{Plages de tarification enregistrées.}}', level: 'success'});
    }, function () {});
});

/* ------------------------------------------------------------------ the cost catch-up of one period */

rates.open = function (_from) {
    if (rates.targets.length == 0) {
        bootbox.alert('{{Aucun équipement « compteur total » d\'électricité : créez-le d\'abord.}}');
        return;
    }
    rates.running = false;
    rates.done = false;
    var energyId = rates.targets[0].id;
    var picker = '';
    if (rates.targets.length > 1) {
        picker = '<p><select id="sel_rateEnergy" class="form-control input-sm">';
        rates.targets.forEach(function (t) { picker += '<option value="' + t.id + '">' + rates.esc(t.name) + '</option>'; });
        picker += '</select></p>';
    }
    rates.box = bootbox.dialog({
        title: '<i class="fas fa-calculator"></i> {{Recalcul des coûts}} · ' + rates.esc(_from),
        size: 'large',
        closeButton: false,
        message: '<div id="div_rateCatchup">' + picker + '<div id="div_rateAlert"></div><div id="div_rateBody"><p><i class="fas fa-spinner fa-spin"></i> {{Analyse en cours…}}</p></div></div>',
        buttons: {
            cancel: {label: '{{Annuler}}', className: 'btn-default', callback: function () { return !rates.running; }},
            apply: {label: '{{Appliquer}}', className: 'btn-success', callback: function () { rates.apply(_from); return false; }}
        }
    });
    rates.box.find('[data-bb-handler=apply]').prop('disabled', true);
    var preview = function () {
        var id = rates.targets.length > 1 ? $('#sel_rateEnergy').val() : energyId;
        $('#div_rateBody').html('<p><i class="fas fa-spinner fa-spin"></i> {{Analyse en cours…}}</p>');
        rates.box.find('[data-bb-handler=apply]').prop('disabled', true);
        rates.call({action: 'catchupRatePreview', energy_id: id, from: _from}, function (result) {
            var s = result.summary;
            var html = '<div class="alert alert-info">' + s.from + ' → ' + s.to + ' · ' + s.days + ' {{jours}} · {{coût actuel}} <strong>' + s.current + '</strong> → {{nouveau}} <strong>' + s.new + '</strong> · {{écart}} ' + s.gap + (s.gapPercent === null ? '' : ' (' + s.gapPercent + ' %)') + ' · ' + s.withoutSplit + ' {{jour(s) sans HC/HP}}</div>';
            html += '<h4>{{Détail du recalcul}} <a class="btn btn-default btn-xs" id="bt_rateCopyPreview"><i class="fas fa-copy"></i> {{Copier}}</a></h4><textarea id="ta_ratePreview" class="form-control" rows="10" readonly style="font-family:monospace;font-size:11px"></textarea>';
            $('#div_rateBody').html(html);
            $('#ta_ratePreview').val(result.text);
            $('#bt_rateCopyPreview').on('click', function () { rates.copy('#ta_ratePreview'); });
            rates.box.find('[data-bb-handler=apply]').prop('disabled', false);
        }, function (error) {
            $('#div_rateBody').empty();
            $('#div_rateAlert').html('<div class="alert alert-danger">' + rates.esc(error) + '</div>');
        });
    };
    $('#sel_rateEnergy').on('change', preview);
    preview();
};

rates.copy = function (_selector) {
    $(_selector).focus().select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    $('#div_rateAlert').html('<div class="alert alert-' + (ok ? 'success' : 'warning') + '">' + (ok ? '{{Détail copié dans le presse-papiers.}}' : '{{Copie impossible : sélectionnez le texte (Ctrl+C).}}') + '</div>');
};

rates.progress = function (_states) {
    var names = Object.keys(rates.steps);
    var done = 0;
    var html = '<ul class="list-unstyled">';
    names.forEach(function (name) {
        var state = _states[name] || 'wait';
        if (state == 'ok') { done++; }
        var icon = {wait: 'fa-circle text-muted', run: 'fa-spinner fa-spin text-primary', ok: 'fa-check-circle text-success', error: 'fa-times-circle text-danger'}[state];
        html += '<li><i class="fas ' + icon + '"></i> ' + rates.esc(rates.steps[name]) + '</li>';
    });
    var percent = Math.round(100 * done / names.length);
    return '<div class="progress"><div class="progress-bar' + (_states.failed ? ' progress-bar-danger' : '') + '" style="width:' + percent + '%">' + percent + ' %</div></div>' + html + '</ul>';
};

rates.apply = function (_from) {
    rates.running = true;
    var footer = rates.box.find('.modal-footer');
    footer.find('[data-bb-handler=apply]').hide();
    footer.find('[data-bb-handler=cancel]').prop('disabled', true);
    var energyId = rates.targets.length > 1 ? $('#sel_rateEnergy').val() : rates.targets[0].id;
    $('#sel_rateEnergy').prop('disabled', true);
    var states = {};
    var id = '';
    var show = function () { $('#div_rateBody').html(rates.progress(states)); };
    var finish = function (_result, _ok) {
        rates.running = false;
        rates.done = _ok;
        var html = rates.progress(states) + '<div class="alert alert-' + (_ok ? 'success' : 'danger') + '"><strong>' + (_ok ? '{{Recalcul terminé sans erreur.}}' : '{{Recalcul interrompu : restaurez la sauvegarde si besoin.}}') + '</strong><br>' + rates.esc(_result.message) + (_result.backup ? '<br>{{Sauvegarde}} : ' + rates.esc(_result.backup) : '') + '</div>';
        html += '<h4>{{Détail du recalcul}} <a class="btn btn-default btn-xs" id="bt_rateCopyResult"><i class="fas fa-copy"></i> {{Copier}}</a></h4><textarea id="ta_rateResult" class="form-control" rows="10" readonly style="font-family:monospace;font-size:11px"></textarea>';
        $('#div_rateBody').html(html);
        $('#ta_rateResult').val(_result.text);
        $('#bt_rateCopyResult').on('click', function () { rates.copy('#ta_rateResult'); });
        footer.find('[data-bb-handler=cancel]').prop('disabled', false).text(_ok ? '{{Fermer}}' : '{{Annuler}}');
    };
    var run = function (_step) {
        states[_step] = 'run';
        show();
        rates.call({action: 'catchupRateStep', id: id, step: _step, energy_id: energyId, from: _from}, function (result) {
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

$(document).on('click', '.bt_rateCatchup', function () {
    if ($(this).hasClass('disabled')) {
        return;
    }
    rates.open($(this).closest('tr').find('.from').val());
});

$(function () {
    if ($('#table_ratePeriods').length) {
        rates.load();
    }
});
