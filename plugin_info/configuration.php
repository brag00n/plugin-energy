<?php
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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
	include_file('desktop', '404', 'php');
	die();
}
?>
<form class="form-horizontal" id="form_configEnergy">
    <fieldset>
        <legend><i class="fa fa-eur"></i> {{Tarification}}</legend>
        <div class="form-group">
            <label class="col-lg-2 control-label">{{Devise}}</label>
            <div class="col-lg-2">
                <input class="configKey form-control" data-l1key="currency" value="€"/>
            </div>
        </div>
        <legend><i class="fa fa-tint"></i> {{Eau}}</legend>
        <div class="form-group">
            <label class="col-sm-2 control-label">{{Activer}}</label>
            <div class="col-sm-2">
                <input type="checkbox" class="configKey enableType" data-size="small" data-type="water" data-l1key="enableWater"/>
            </div>
        </div>
        <div class="waterConfig" style="display : none;">
            <div class="form-group">
                <label class="col-sm-2 control-label">{{Tarif}}</label>
                <div class="col-sm-4">
                    <div class="input-group">
                        <input class="form-control configKey input-sm" data-l1key="rateWater"/>
                        <span class="input-group-btn">
                          <a class="btn btn-default btn-sm listCmdInfo btn-warning"  data-input="rateWater"><i class="fa fa-list-alt"></i></a>
                      </span>
                  </div>
              </div>
          </div>
          <div class="form-group">
              <label class="col-sm-2 control-label">{{Température extérieure}} <sup><i class="fas fa-question-circle tooltips" title="{{Commande d’information de la température extérieure (par exemple celle du plugin Météo). Elle est tracée en courbe (moyenne par jour) sur les graphiques de consommation, sans modifier les totaux. Vide = pas de courbe.}}"></i></sup></label>
              <div class="col-sm-4">
                  <div class="input-group">
                      <input class="form-control configKey input-sm" data-l1key="outdoorTemperatureWater"/>
                      <span class="input-group-btn">
                        <a class="btn btn-default btn-sm listCmdInfo btn-warning" data-input="outdoorTemperatureWater"><i class="fa fa-list-alt"></i></a>
                      </span>
                  </div>
              </div>
          </div>
      </div>

      <legend><i class="icon nature-fire14"></i> {{Gaz}}</legend>
      <div class="form-group">
        <label class="col-sm-2 control-label">{{Activer}}</label>
        <div class="col-sm-2">
            <input type="checkbox" class="configKey enableType" data-type="gas" data-l1key="enableGas"/>
        </div>
    </div>
    <div class="gasConfig" style="display : none;">
        <div class="form-group">
            <label class="col-sm-2 control-label">{{Tarif}}</label>
            <div class="col-sm-4">
             <div class="input-group">
                <input class="form-control configKey input-sm" data-l1key="rateGas"/>
                <span class="input-group-btn">
                  <a class="btn btn-default btn-sm listCmdInfo btn-warning"  data-input="rateGas"><i class="fa fa-list-alt"></i></a>
              </span>
          </div>
      </div>
  </div>
        <div class="form-group">
            <label class="col-sm-2 control-label">{{Température extérieure}} <sup><i class="fas fa-question-circle tooltips" title="{{Commande d’information de la température extérieure (par exemple celle du plugin Météo). Elle est tracée en courbe (moyenne par jour) sur les graphiques de consommation, sans modifier les totaux. Vide = pas de courbe.}}"></i></sup></label>
            <div class="col-sm-4">
                <div class="input-group">
                    <input class="form-control configKey input-sm" data-l1key="outdoorTemperatureGas"/>
                    <span class="input-group-btn">
                      <a class="btn btn-default btn-sm listCmdInfo btn-warning" data-input="outdoorTemperatureGas"><i class="fa fa-list-alt"></i></a>
                    </span>
                </div>
            </div>
        </div>
</div>

<legend><i class="fa fa-bolt"></i> {{Electricité}}</legend>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Activer}}</label>
    <div class="col-sm-2">
        <input type="checkbox" class="configKey enableType" data-type="electricity" data-l1key="enableElectricity"/>
    </div>
</div>
<div class="electricityConfig" style="display : none;">
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Tarification}}</label>
        <div class="col-sm-2">
            <select class="form-control configKey input-sm" data-l1key="rateMode">
                <option value="fixed">Fixe</option>
                <option value="variable">Heures pleines/heures creuses</option>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Abonnement par jour}}</label>
        <div class="col-sm-2">
            <input class="form-control configKey input-sm" data-l1key="subscriptionDaily" placeholder="0"/>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Puissance souscrite (kVA)}}</label>
        <div class="col-sm-2">
            <input class="form-control configKey input-sm" data-l1key="energySubscribedPower" placeholder="0"/>
        </div>
        <div class="col-sm-6"><span class="help-block">{{Un index qui saute de plus que cette puissance (x1,5) multipliée par la durée du cycle est ignoré avec une alerte. 0 = désactivé.}}</span></div>
    </div>
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Compteur total fiable à partir du}}</label>
        <div class="col-sm-2">
            <input class="form-control configKey input-sm" data-l1key="counterReliableSince" placeholder="AAAA-MM-JJ"/>
        </div>
        <div class="col-sm-6"><span class="help-block">{{Avant cette date, le compteur total est ignoré et les jours gardent la somme des équipements (un compteur vidé ou installé en cours de journée ne contient qu'une partie de ce jour). Vide = pas de limite.}}</span></div>
    </div>
      <div class="form-group">
        <label class="col-sm-2 control-label">{{Température extérieure}} <sup><i class="fas fa-question-circle tooltips" title="{{Commande d’information de la température extérieure (par exemple celle du plugin Météo). Elle est tracée en courbe (moyenne par jour) sur les graphiques de consommation, sans modifier les totaux. Vide = pas de courbe.}}"></i></sup></label>
        <div class="col-sm-4">
            <div class="input-group">
                <input class="form-control configKey input-sm" data-l1key="outdoorTemperature"/>
                <span class="input-group-btn">
                  <a class="btn btn-default btn-sm listCmdInfo btn-warning" data-input="outdoorTemperature"><i class="fa fa-list-alt"></i></a>
                </span>
            </div>
        </div>
    </div>
    <div class="rate fixed">
        <div class="form-group">
            <label class="col-sm-2 control-label">{{Tarif}}</label>
            <div class="col-sm-4">
                <div class="input-group">
                    <input class="form-control configKey input-sm" data-l1key="rate"/>
                    <span class="input-group-btn">
                      <a class="btn btn-default btn-sm listCmdInfo btn-warning"  data-input="rate"><i class="fa fa-list-alt"></i></a>
                  </span>
              </div>
          </div>
      </div>
  </div>
  <div class="rate variable" style="display : none;">
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Tarif heure pleine}}</label>
        <div class="col-sm-2">
            <input class="form-control configKey input-sm" data-l1key="rateHp"/>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Tarif heure creuse}}</label>
        <div class="col-sm-2">
            <input class="form-control configKey input-sm" data-l1key="rateHc"/>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Début heure creuse}}</label>
        <div class="col-sm-1">
            <input class="form-control configKey input-sm timepicker" data-l1key="startHc"/>
        </div>
        <label class="col-sm-2 control-label">{{Fin heure creuse}}</label>
        <div class="col-sm-1">
            <input class="form-control configKey input-sm timepicker" data-l1key="endHc"/>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Début heure creuse 2}}</label>
        <div class="col-sm-1">
            <input class="form-control configKey input-sm timepicker" data-l1key="startHc2"/>
        </div>
        <label class="col-sm-2 control-label">{{Fin heure creuse 2}}</label>
        <div class="col-sm-1">
            <input class="form-control configKey input-sm timepicker" data-l1key="endHc2"/>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-2 control-label">{{Début heure creuse 3}}</label>
        <div class="col-sm-1">
            <input class="form-control configKey input-sm timepicker" data-l1key="startHc3"/>
        </div>
        <label class="col-sm-2 control-label">{{Fin heure creuse 3}}</label>
        <div class="col-sm-1">
            <input class="form-control configKey input-sm timepicker" data-l1key="endHc3"/>
        </div>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Plages de tarification}} <sup><i class="fas fa-question-circle tooltips" title="{{Chaque plage donne ses propres tarifs à une période de dates : le coût calculé par le plugin et le panneau utilisent la plage qui contient la date. Une date hors de toute plage utilise les tarifs ci-dessus. Un champ laissé vide reprend la valeur ci-dessus. « Au » est exclu, vide = plage en cours.}}"></i></sup></label>
    <div class="col-sm-10">
        <table class="table table-condensed" id="table_ratePeriods">
            <thead><tr><th>{{Du (inclus)}}</th><th>{{Au (exclu)}}</th><th>{{Tarif HP}}</th><th>{{Tarif HC}}</th><th>{{Tarif fixe}}</th><th>{{Abonnement/jour}}</th><th>{{Heures creuses}}</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
        <a class="btn btn-default btn-sm" id="bt_rateAdd"><i class="fas fa-plus-circle"></i> {{Ajouter une plage}}</a>
        <a class="btn btn-success btn-sm" id="bt_rateSave"><i class="fas fa-save"></i> {{Enregistrer les plages}}</a>
        <span class="help-block">{{Enregistrez les plages avant de recalculer les coûts : le calcul lit les plages enregistrées.}}</span>
    </div>
</div>
</div>

<legend><i class="fas fa-history"></i> {{Rattrapage depuis Enedis}}</legend>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Import}} <sup><i class="fas fa-question-circle tooltips" title="{{Complète l'historique du compteur total avec l'export quotidien d'Enedis et le HC/HP de vos factures EDF, saisi à la main. Une sauvegarde de l'historique concerné est faite avant toute écriture.}}"></i></sup></label>
    <div class="col-sm-4">
        <a class="btn btn-default btn-sm" id="bt_catchupOpen"><i class="fas fa-file-import"></i> {{Importer un export Enedis}}</a>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Sauvegardes}} <sup><i class="fas fa-question-circle tooltips" title="{{Une sauvegarde est créée avant chaque import : « Restaurer » remet l'historique des jours importés tel qu'il était. Rien n'est supprimé automatiquement.}}"></i></sup></label>
    <div class="col-sm-8">
        <table class="table table-condensed" id="table_catchupBackups">
            <thead><tr><th>{{Date}}</th><th>{{Plage}}</th><th>{{Jours}}</th><th>{{Lignes}}</th><th></th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<legend><i class="fa fa-file-text-o"></i> {{Logs}}</legend>
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-10">
        <div class="alert alert-info">
            {{Ces cases choisissent quelles traces de debug sont écrites. Elles ne servent que lorsque le niveau de log du plugin est sur « Debug » (Réglages → Système → Configuration → Logs). Les alertes (saut de consommation, cron en retard) sont toujours écrites.}}
        </div>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Performance}}</label>
    <div class="col-sm-4">
        <input type="checkbox" class="configKey" data-l1key="logPerf"/>
        <span class="help-block">{{Durées et volumes de l'affichage du tableau de bord (lignes [perf]).}}</span>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Anomalie de consommation}}</label>
    <div class="col-sm-4">
        <input type="checkbox" class="configKey" data-l1key="logAnomaly"/>
        <span class="help-block">{{Décisions de la détection des sauts de consommation et cycles du cron (lignes [jump] et [cron]).}}</span>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Rattrapage}}</label>
    <div class="col-sm-4">
        <input type="checkbox" class="configKey" data-l1key="logCatchup"/>
        <span class="help-block">{{Étapes du rattrapage de l'historique depuis Enedis (lignes [catchup], jour par jour).}}</span>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Tarification}}</label>
    <div class="col-sm-4">
        <input type="checkbox" class="configKey" data-l1key="logRates"/>
        <span class="help-block">{{Plages de tarification et recalcul des coûts d'une plage (lignes [rates], jour par jour).}}</span>
    </div>
</div>
</fieldset>
</form>

<?php include_file('desktop', 'catchup', 'js', 'energy');?>
<?php include_file('desktop', 'rates', 'js', 'energy');?>
<script>
 $('#form_configEnergy').delegate('.enableType','change', function () {
    if($(this).value() == 1){
        $('.'+$(this).attr('data-type')+'Config').show();
    }else{
       $('.'+$(this).attr('data-type')+'Config').hide();
   }
});

 $('.enableType').on('change', function () {
    if($(this).value() == 1){
        $('.'+$(this).attr('data-type')+'Config').show();
    }else{
       $('.'+$(this).attr('data-type')+'Config').hide();
   }
});

 $('.configKey[data-l1key=rateMode]').on('change', function () {
    $('.rate').hide();
    $('.rate.' + $(this).value()).show();
});

 $("#form_configEnergy").delegate(".listCmdInfo", 'click', function () {
    var el = $('.configKey[data-l1key=' + $(this).attr('data-input') + ']');
    jeedom.cmd.getSelectModal({cmd: {type: 'info'}}, function (result) {
        el.atCaret('insert', result.human);
    });
});

 $('.timepicker').datetimepicker({
    lang: 'fr',
    datepicker: false,
    format: 'H:i',
    step: 15
});
</script>
