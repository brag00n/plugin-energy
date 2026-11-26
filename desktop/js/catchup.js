/* Import modal of the catch-up of the history from an Enedis export (see core/class/energyCatchup.class.php).
 * Four steps: 1 Enedis file, 2 EDF periods typed by hand, 3 analysis, 4 import with a progress bar.
 * The browser only sends choices (equipment, dates, option, periods): the server re-validates everything at each call. */

var catchup = {
    url: 'plugins/energy/core/ajax/energy.ajax.php',
    file: null,
    targets: [],
    steps: {},
    step: 1,
    running: false,
    imported: false,
    analysis: null,
    box: null
};

catchup.call = function (_data, _success, _error, _ajaxOptions) {
    $.ajax($.extend({
        type: 'POST',
        url: catchup.url,
        data: _data,
        dataType: 'json',
        // no global ajax events: Jeedom shows its blurred loading screen on ajaxStart, which hides the progress of the import
        global: false,
        error: function (request, status, error) {
            if (_error) {
                _error(catchup.plain(error));
            } else {
                handleAjaxError(request, status, error);
            }
        },
        success: function (data) {
            if (data.state != 'ok') {
                if (_error) {
                    _error(catchup.plain(data.result));
                } else {
                    $('#div_catchupAlert').empty().append('<div class="alert alert-danger">' + $('<div>').text(catchup.plain(data.result)).html() + '</div>');
                }
                return;
            }
            _success(data.result);
        }
    }, _ajaxOptions || {}));
};

/* Jeedom wraps the message of an ajax error in <span id="span_errorMessage">…</span>: only the text is shown */
catchup.plain = function (_message) {
    return $('<div>').html(_message === null || _message === undefined ? '' : String(_message)).text();
};

catchup.esc = function (_text) {
    return $('<div>').text(_text === null || _text === undefined ? '' : _text).html();
};

catchup.alert = function (_level, _message) {
    $('#div_catchupAlert').empty().append('<div class="alert alert-' + _level + '">' + catchup.esc(_message) + '</div>');
};

catchup.today = function () {
    return new Date().toISOString().substr(0, 10);
};

/* ------------------------------------------------------------------ dates */

catchup.addMonth = function (_date) {
    var parts = _date.split('-');
    var year = parseInt(parts[0], 10), month = parseInt(parts[1], 10) - 1, day = parseInt(parts[2], 10);
    var next = new Date(Date.UTC(year, month + 1, 1));
    var last = new Date(Date.UTC(next.getUTCFullYear(), next.getUTCMonth() + 1, 0)).getUTCDate();
    next.setUTCDate(Math.min(day, last));
    return next.toISOString().substr(0, 10);
};

catchup.addDay = function (_date, _n) {
    var d = new Date(_date + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + _n);
    return d.toISOString().substr(0, 10);
};

/* ------------------------------------------------------------------ modal */

catchup.open = function () {
    catchup.file = null;
    catchup.step = 1;
    catchup.imported = false;
    catchup.running = false;
    catchup.analysis = null;
    catchup.call({action: 'catchupInit'}, function (init) {
        catchup.targets = init.targets;
        catchup.steps = init.steps;
        catchup.savedPeriods = init.periods || [];
        if (catchup.targets.length == 0) {
            bootbox.alert('{{Aucun équipement « compteur total » d\'électricité : créez-le d\'abord.}}');
            return;
        }
        catchup.box = bootbox.dialog({
            title: '<i class="fas fa-history"></i> {{Rattrapage depuis Enedis}}',
            size: 'large',
            closeButton: false,
            message: '<div id="div_catchup"></div>',
            buttons: {
                cancel: {label: '{{Annuler}}', className: 'btn-default', callback: function () { return catchup.cancel(); }},
                back: {label: '{{Retour}}', className: 'btn-default', callback: function () { catchup.go(catchup.step - 1); return false; }},
                next: {label: '{{Suivant}}', className: 'btn-primary', callback: function () { catchup.next(); return false; }}
            }
        });
        catchup.box.on('shown.bs.modal', function () { catchup.render(); });
        catchup.render();
    });
};

catchup.cancel = function () {
    if (catchup.running) {
        return false;
    }
    if (catchup.file && !catchup.imported) {
        catchup.call({action: 'catchupDiscard', id: catchup.file.id}, function () {}, function () {});
    }
    if (catchup.imported) {
        window.location.reload();
    }
    return true;
};

catchup.buttons = function () {
    var footer = catchup.box.find('.modal-footer');
    footer.find('[data-bb-handler=back]').toggle(catchup.step > 1 && !catchup.running && !catchup.imported);
    footer.find('[data-bb-handler=next]').toggle(catchup.step < 4).prop('disabled', !catchup.canNext());
    footer.find('[data-bb-handler=cancel]').text(catchup.imported ? '{{Fermer}}' : '{{Annuler}}').prop('disabled', catchup.running);
};

catchup.canNext = function () {
    if (catchup.step == 1) {
        return catchup.file !== null;
    }
    return !catchup.running;
};

catchup.go = function (_step) {
    if (_step < 1 || catchup.running) {
        return;
    }
    catchup.step = _step;
    catchup.render();
};

catchup.next = function () {
    if (catchup.step == 1) {
        if (!catchup.readStep1()) {
            return;
        }
        catchup.go(2);
    } else if (catchup.step == 2) {
        var periods = catchup.readPeriods();
        if (periods === false) {
            return;
        }
        catchup.call({action: 'catchupSavePeriods', periods: JSON.stringify(periods)}, function () {
            catchup.go(3);
        }, function (error) {
            catchup.alert('danger', error);
        });
    } else if (catchup.step == 3) {
        catchup.go(4);
    }
};

catchup.render = function () {
    var titles = {1: '{{Import des données ENEDIS}}', 2: '{{Périodes EDF (saisie manuelle)}}', 3: '{{Analyse des données}}', 4: '{{Application}}'};
    var html = '<ol class="breadcrumb" style="margin-bottom:10px">';
    for (var i = 1; i <= 4; i++) {
        html += '<li' + (i == catchup.step ? ' class="active"><strong>' : '>') + i + ' · ' + titles[i] + (i == catchup.step ? '</strong>' : '') + '</li>';
    }
    html += '</ol><div id="div_catchupAlert"></div><div id="div_catchupBody"></div>';
    $('#div_catchup').html(html);
    catchup['render' + catchup.step]();
    catchup.buttons();
};

/* ------------------------------------------------------------------ step 1 */

catchup.render1 = function () {
    var f = catchup.file;
    var html = '<div class="form-horizontal">';
    html += '<div class="form-group"><label class="col-sm-3 control-label">{{Équipement}} <sup><i class="fas fa-question-circle tooltips" title="{{Équipement « compteur total » d\'électricité dont l\'historique est complété.}}"></i></sup></label><div class="col-sm-7"><select id="sel_catchupEnergy" class="form-control input-sm">';
    catchup.targets.forEach(function (t) {
        html += '<option value="' + t.id + '"' + (f && f.energyId == t.id ? ' selected' : '') + '>' + catchup.esc(t.name) + '</option>';
    });
    html += '</select></div></div>';
    html += '<div class="form-group"><label class="col-sm-3 control-label">{{Fichier Enedis (.xlsx)}} <sup><i class="fas fa-question-circle tooltips" title="{{Export de la consommation quotidienne téléchargé sur le site d\'Enedis. Le fichier est contrôlé dès son envoi.}}"></i></sup></label><div class="col-sm-7"><input type="file" id="in_catchupFile" accept=".xlsx"></div></div>';
    if (f) {
        html += '<div class="alert alert-info" style="margin:10px 15px">';
        html += '<strong>' + catchup.esc(f.name) + '</strong><br>';
        html += '{{PRM}} : ' + catchup.esc(f.prm) + ' · ' + catchup.esc(f.type) + '<br>';
        html += f.count + ' {{jours}} : ' + f.from + ' → ' + f.to + ' · ' + f.total + ' kWh (min ' + f.min + ', max ' + f.max + ')<br>';
        html += '{{Jours indisponibles}} : ' + f.unavailable.length + ' · {{trous}} : ' + f.gaps.length + ' · {{valeurs refusées}} : ' + f.invalid.length;
        html += f.hasSplit ? '<br>{{Le fichier contient des colonnes HC/HP.}}' : '<br>{{Le fichier ne contient que le total de chaque jour (pas de HC/HP).}}';
        html += '</div>';
        html += '<div class="form-group"><label class="col-sm-3 control-label">{{Plage à importer}} <sup><i class="fas fa-question-circle tooltips" title="{{Premier et dernier jour (inclus) à importer, dans la plage du fichier. Pré-rempli avec la plage du fichier.}}"></i></sup></label>';
        html += '<div class="col-sm-3"><input type="date" id="in_catchupFrom" class="form-control input-sm" value="' + f.rangeFrom + '" min="' + f.from + '" max="' + f.to + '"></div>';
        html += '<div class="col-sm-3"><input type="date" id="in_catchupTo" class="form-control input-sm" value="' + f.rangeTo + '" min="' + f.from + '" max="' + f.to + '"></div></div>';
        html += '<div class="form-group"><div class="col-sm-offset-3 col-sm-8"><label><input type="checkbox" id="cb_catchupOnlyEmpty"' + (f.onlyEmpty ? ' checked' : '') + '> {{Ne rattraper que les jours sans données du plugin}}</label> <sup><i class="fas fa-question-circle tooltips" title="{{Cochée : les jours qui ont déjà une consommation enregistrée par le plugin sont conservés. Décochée : ils sont remplacés par les valeurs d\'Enedis (après sauvegarde).}}"></i></sup></div></div>';
    }
    html += '</div>';
    $('#div_catchupBody').html(html);
    $('#in_catchupFile').on('change', catchup.upload);
};

catchup.upload = function () {
    var input = document.getElementById('in_catchupFile');
    if (!input.files.length) {
        return;
    }
    var previous = catchup.file;
    var form = new FormData();
    form.append('action', 'catchupUpload');
    form.append('file', input.files[0]);
    catchup.alert('info', '{{Contrôle du fichier…}}');
    catchup.call(form, function (file) {
        if (previous) {
            catchup.call({action: 'catchupDiscard', id: previous.id}, function () {}, function () {});
        }
        file.energyId = $('#sel_catchupEnergy').val();
        file.rangeFrom = file.from;
        file.rangeTo = file.to < catchup.addDay(catchup.today(), -1) ? file.to : catchup.addDay(catchup.today(), -1);
        file.onlyEmpty = true;
        catchup.file = file;
        catchup.render();
    }, function (error) {
        catchup.file = null;
        catchup.render();
        catchup.alert('danger', error);
    }, {data: form, processData: false, contentType: false});
};

catchup.readStep1 = function () {
    var f = catchup.file;
    f.energyId = $('#sel_catchupEnergy').val();
    f.rangeFrom = $('#in_catchupFrom').val();
    f.rangeTo = $('#in_catchupTo').val();
    f.onlyEmpty = $('#cb_catchupOnlyEmpty').is(':checked');
    if (!f.rangeFrom || !f.rangeTo || f.rangeFrom > f.rangeTo || f.rangeFrom < f.from || f.rangeTo > f.to) {
        catchup.alert('danger', '{{La plage doit rester dans celle du fichier, début avant fin.}}');
        return false;
    }
    return true;
};

/* ------------------------------------------------------------------ step 2 */

catchup.render2 = function () {
    var html = '<p class="text-muted">{{Saisissez les périodes de vos factures EDF (la facture n\'est pas importée). HC et HP sont pré-remplis quand l\'historique du plugin les connaît ; vous pouvez les modifier ou les compléter. Une période sans HC/HP ne donne que le total des jours.}}</p>';
    html += '<table class="table table-condensed" id="table_catchupPeriods"><thead><tr><th>{{Du (inclus)}}</th><th>{{Au (exclu)}}</th><th>{{Total Enedis}}</th><th>{{HC (kWh)}}</th><th>{{HP (kWh)}}</th><th>{{Source}}</th><th></th></tr></thead><tbody></tbody></table>';
    html += '<a class="btn btn-default btn-sm" id="bt_catchupAddPeriod"><i class="fas fa-plus-circle"></i> {{Ajouter une période}}</a> ';
    html += '<a class="btn btn-default btn-sm" id="bt_catchupClearPeriods"><i class="fas fa-eraser"></i> {{Vider}}</a>';
    $('#div_catchupBody').html(html);
    (catchup.periods || catchup.savedPeriods || []).forEach(function (p) {
        catchup.addPeriodRow(p, p.source || 'saisi');
    });
    $('#bt_catchupAddPeriod').on('click', catchup.addNextPeriod);
    $('#bt_catchupClearPeriods').on('click', function () {
        $('#table_catchupPeriods tbody').empty();
        catchup.refreshAddButton();
    });
    $('#table_catchupPeriods').on('click', '.bt_catchupRemove', function () {
        $(this).closest('tr').remove();
        catchup.refreshAddButton();
    });
    $('#table_catchupPeriods').on('input change', 'input', function () {
        var row = $(this).closest('tr');
        if ($(this).hasClass('hc') || $(this).hasClass('hp')) {
            row.find('.source').text('saisi');
        }
        catchup.refreshTotals();
    });
    catchup.refreshAddButton();
};

catchup.lastEnd = function () {
    var end = null;
    $('#table_catchupPeriods tbody tr').each(function () {
        var to = $(this).find('.to').val();
        if (to && (end === null || to > end)) {
            end = to;
        }
    });
    return end;
};

/* the enedis file only gives the total of each day: the sum of a period is known only once the analysis has run, so the
 * "Total Enedis" column is filled by the server (step 3); here it shows the days count */
catchup.addPeriodRow = function (_period, _source) {
    var row = '<tr><td><input type="date" class="form-control input-sm from" value="' + (_period.from || '') + '"></td>';
    row += '<td><input type="date" class="form-control input-sm to" value="' + (_period.to || '') + '"></td>';
    row += '<td class="total">–</td>';
    row += '<td><input type="text" class="form-control input-sm hc" value="' + (_period.hc === null || _period.hc === undefined ? '' : _period.hc) + '"></td>';
    row += '<td><input type="text" class="form-control input-sm hp" value="' + (_period.hp === null || _period.hp === undefined ? '' : _period.hp) + '"></td>';
    row += '<td class="source">' + catchup.esc(_source || '—') + '</td>';
    row += '<td><a class="btn btn-danger btn-xs bt_catchupRemove"><i class="fas fa-minus-circle"></i></a></td></tr>';
    $('#table_catchupPeriods tbody').append(row);
    catchup.refreshTotals();
};

catchup.refreshTotals = function () {
    $('#table_catchupPeriods tbody tr').each(function () {
        var from = $(this).find('.from').val(), to = $(this).find('.to').val();
        $(this).find('.total').text(from && to && from < to ? Math.round((new Date(to) - new Date(from)) / 86400000) + ' {{jours}}' : '–');
    });
};

/* the next month: from the end of the last period (or the first day of the import), to the same day one month later,
 * never beyond the day after the last day of the import */
catchup.addNextPeriod = function () {
    var f = catchup.file;
    var start = catchup.lastEnd() || f.rangeFrom;
    var limit = catchup.addDay(f.rangeTo, 1);
    if (start >= limit) {
        return;
    }
    var end = catchup.addMonth(start);
    if (end > limit) {
        end = limit;
    }
    catchup.addPeriodRow({from: start, to: end}, '—');
    var row = $('#table_catchupPeriods tbody tr:last');
    catchup.call({action: 'catchupPeriodHint', energy_id: f.energyId, from: start, to: end}, function (hint) {
        if (hint) {
            row.find('.hc').val(hint.hc);
            row.find('.hp').val(hint.hp);
            row.find('.source').text('plugin');
        }
    }, function () {});
    catchup.refreshAddButton();
};

catchup.refreshAddButton = function () {
    var f = catchup.file;
    var covered = (catchup.lastEnd() || f.rangeFrom) >= catchup.addDay(f.rangeTo, 1);
    $('#bt_catchupAddPeriod').prop('disabled', covered).attr('title', covered ? '{{Plage Enedis couverte}}' : '');
};

catchup.readPeriods = function () {
    var periods = [];
    $('#table_catchupPeriods tbody tr').each(function () {
        periods.push({from: $(this).find('.from').val(), to: $(this).find('.to').val(), hc: $(this).find('.hc').val(), hp: $(this).find('.hp').val(), source: $(this).find('.source').text()});
    });
    catchup.periods = periods;
    return periods;
};

/* ------------------------------------------------------------------ step 3 */

catchup.params = function () {
    var f = catchup.file;
    return {energy_id: f.energyId, from: f.rangeFrom, to: f.rangeTo, only_empty: f.onlyEmpty ? 1 : 0, periods: catchup.periods || []};
};

catchup.render3 = function () {
    $('#div_catchupBody').html('<p><i class="fas fa-spinner fa-spin"></i> {{Analyse en cours…}}</p>');
    catchup.call({action: 'catchupAnalyze', id: catchup.file.id, params: JSON.stringify(catchup.params())}, function (result) {
        catchup.analysis = result;
        var s = result.summary;
        var html = '<div class="alert alert-info">' + s.write + ' {{à écrire}} · ' + s.replace + ' {{remplacés}} · ' + s.keep + ' {{conservés (données du plugin)}} · ' + s.kwh + ' kWh · {{coût estimé}} ' + s.cost + ' · ' + s.withoutSplit + ' {{jour(s) sans HC/HP}} · {{compteur fiable à partir du}} ' + s.reliableFrom + '</div>';
        if (!catchup.file.onlyEmpty && s.replace > 0) {
            html += '<div class="alert alert-warning">' + s.replace + ' {{jour(s) du plugin seront remplacés (sauvegarde automatique avant écriture).}}</div>';
        }
        html += '<h4>{{Périodes}}</h4><table class="table table-condensed"><tr><th>{{Période}}</th><th>{{Total Enedis}}</th><th>{{Facture HC+HP}}</th><th>{{Écart}}</th></tr>';
        result.periods.forEach(function (p) {
            html += '<tr' + (p.warning ? ' class="danger"' : '') + '><td>' + p.from + ' → ' + p.to + '</td><td>' + p.enedis + '</td><td>' + (p.invoice === null ? '–' : p.invoice) + '</td><td>' + (p.gapPercent === null ? '–' : p.gapPercent + ' %') + (p.warning ? ' ⚠' : '') + '</td></tr>';
        });
        html += '</table>';
        html += '<h4>{{Détail de l\'analyse}} <a class="btn btn-default btn-xs" id="bt_catchupCopyAnalysis"><i class="fas fa-copy"></i> {{Copier}}</a></h4>';
        html += '<textarea id="ta_catchupAnalysis" class="form-control" rows="12" readonly style="font-family:monospace;font-size:11px"></textarea>';
        $('#div_catchupBody').html(html);
        $('#ta_catchupAnalysis').val(result.text);
        $('#bt_catchupCopyAnalysis').on('click', function () { catchup.copy('#ta_catchupAnalysis'); });
        catchup.buttons();
    }, function (error) {
        $('#div_catchupBody').empty();
        catchup.alert('danger', error);
    });
};

catchup.copy = function (_selector) {
    var field = $(_selector);
    field.focus().select();
    var done = false;
    try {
        done = document.execCommand('copy');
    } catch (e) {}
    $('#div_catchupAlert').empty().append('<div class="alert alert-' + (done ? 'success' : 'warning') + '">' + (done ? '{{Détail copié dans le presse-papiers.}}' : '{{Copie impossible : sélectionnez le texte (Ctrl+C).}}') + '</div>');
};

/* ------------------------------------------------------------------ step 4 */

catchup.render4 = function () {
    var html = '<p>{{L\'import commence par une sauvegarde de l\'historique concerné ; rien n\'est écrit si elle échoue.}}</p>';
    html += '<a class="btn btn-success" id="bt_catchupRun"><i class="fas fa-play"></i> {{Lancer l\'import}}</a>';
    html += '<div id="div_catchupProgress" style="margin-top:15px"></div>';
    $('#div_catchupBody').html(html);
    $('#bt_catchupRun').on('click', catchup.run);
};

catchup.progressHtml = function (_states) {
    var names = Object.keys(catchup.steps);
    var done = 0;
    var html = '<ul class="list-unstyled">';
    names.forEach(function (name) {
        var state = _states[name] || 'wait';
        if (state == 'ok') {
            done++;
        }
        var icon = {wait: 'fa-circle text-muted', run: 'fa-spinner fa-spin text-primary', ok: 'fa-check-circle text-success', error: 'fa-times-circle text-danger'}[state];
        html += '<li><i class="fas ' + icon + '"></i> ' + catchup.esc(catchup.steps[name]) + '</li>';
    });
    html += '</ul>';
    var percent = Math.round(100 * done / names.length);
    return '<div class="progress"><div class="progress-bar' + (_states.failed ? ' progress-bar-danger' : '') + '" style="width:' + percent + '%">' + percent + ' %</div></div>' + html;
};

catchup.run = function () {
    catchup.running = true;
    $('#bt_catchupRun').prop('disabled', true);
    catchup.buttons();
    var states = {};
    var params = JSON.stringify(catchup.params());
    var show = function () { $('#div_catchupProgress').html(catchup.progressHtml(states)); };
    var finish = function (_result, _ok) {
        catchup.running = false;
        // the button becomes "Fermer" only when everything was imported; after an error it stays "Annuler"
        catchup.imported = _ok;
        var html = catchup.progressHtml(states);
        html += '<div class="alert alert-' + (_ok ? 'success' : 'danger') + '"><strong>' + (_ok ? '{{Import terminé sans erreur.}}' : '{{Import interrompu : corrigez puis restaurez la sauvegarde si besoin.}}') + '</strong><br>' + catchup.esc(_result.message) + (_result.backup ? '<br>{{Sauvegarde}} : ' + catchup.esc(_result.backup) : '') + '</div>';
        html += '<h4>{{Détail de l\'import}} <a class="btn btn-default btn-xs" id="bt_catchupCopyImport"><i class="fas fa-copy"></i> {{Copier}}</a></h4><textarea id="ta_catchupImport" class="form-control" rows="12" readonly style="font-family:monospace;font-size:11px"></textarea>';
        $('#div_catchupProgress').html(html);
        $('#ta_catchupImport').val(_result.text);
        $('#bt_catchupCopyImport').on('click', function () { catchup.copy('#ta_catchupImport'); });
        catchup.buttons();
    };
    var runStep = function (_step) {
        states[_step] = 'run';
        show();
        catchup.call({action: 'catchupStep', id: catchup.file.id, step: _step, params: params}, function (result) {
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
                runStep(result.next);
            }
        }, function (error) {
            states[_step] = 'error';
            states.failed = true;
            finish({message: error, text: error, backup: null}, false);
        });
    };
    runStep('check');
};

$(document).on('click', '#bt_catchupOpen', catchup.open);

/* ------------------------------------------------------------------ the backups, in the configuration page */

catchup.loadBackups = function () {
    catchup.call({action: 'catchupInit'}, function (init) {
        var html = '';
        init.backups.forEach(function (b) {
            var origin = b.kind == 'rates' ? '{{MAJ tarifs}}' : '{{Import Enedis}}';
            if (b.kind == 'rates' && b.tariff) {
                // the tariffs that made these costs, so that a backup tells what it holds
                origin += '<br><small class="text-muted">' + (b.tariff.variable ? 'HP ' + b.tariff.rateHp + ' / HC ' + b.tariff.rateHc : '{{tarif fixe}} ' + b.tariff.rate) + ' · {{abo}} ' + b.tariff.subscriptionDaily + (b.tariff.from ? ' · ' + b.tariff.from + ' → ' + (b.tariff.to || '…') : '') + '</small>';
            }
            html += '<tr><td>' + catchup.esc(b.created) + '</td><td>' + origin + '</td><td>' + b.from + ' → ' + b.to + '</td><td>' + b.days + '</td><td>' + b.rows + '</td><td>' + (b.restored ? '{{restaurée}}' : '') + '</td>';
            html += '<td><a class="btn btn-warning btn-xs bt_catchupRestore" data-has-periods="' + (b.hasPeriods ? 1 : 0) + '" data-name="' + catchup.esc(b.name) + '"><i class="fas fa-undo"></i> {{Restaurer}}</a> <a class="btn btn-danger btn-xs bt_catchupDelete" data-name="' + catchup.esc(b.name) + '"><i class="fas fa-trash"></i></a></td></tr>';
        });
        $('#table_catchupBackups tbody').html(html || '<tr><td colspan="7" class="text-muted">{{Aucune sauvegarde.}}</td></tr>');
    }, function () {});
};

$(document).on('click', '.bt_catchupRestore', function () {
    var name = $(this).attr('data-name');
    var hasPeriods = $(this).attr('data-has-periods') == 1;
    var restore = function (withPeriods) {
        catchup.call({action: 'catchupRestore', name: name, with_periods: withPeriods ? 1 : 0}, function (text) {
            bootbox.alert('<pre style="max-height:300px;overflow:auto">' + catchup.esc(text) + '</pre>', function () { window.location.reload(); });
            catchup.loadBackups();
        }, function (error) { bootbox.alert(catchup.esc(error)); });
    };
    if (!hasPeriods) {
        bootbox.confirm('{{Restaurer l\'historique tel qu\'il était avant cet import ? Les jours écrits par l\'import seront effacés.}}', function (ok) {
            if (ok) { restore(false); }
        });
        return;
    }
    // a recalculation of the costs: the costs come back, and the table of the tariff periods can come back with them
    bootbox.dialog({
        message: '{{Restaurer les coûts tels qu\'ils étaient avant ce recalcul ? Vous pouvez aussi remettre le tableau des plages de tarification tel qu\'il était à ce moment-là.}}',
        buttons: {
            cancel: {label: '{{Annuler}}', className: 'btn-default'},
            costs: {label: '{{Les coûts seulement}}', className: 'btn-warning', callback: function () { restore(false); }},
            both: {label: '{{Les coûts et les plages}}', className: 'btn-danger', callback: function () { restore(true); }}
        }
    });
});

$(document).on('click', '.bt_catchupDelete', function () {
    var name = $(this).attr('data-name');
    bootbox.confirm('{{Supprimer cette sauvegarde ? Elle ne pourra plus être restaurée.}}', function (ok) {
        if (ok) {
            catchup.call({action: 'catchupDelete', name: name}, function () { catchup.loadBackups(); }, function (error) { bootbox.alert(catchup.esc(error)); });
        }
    });
});

$(function () {
    if ($('#table_catchupBackups').length) {
        catchup.loadBackups();
    }
});
