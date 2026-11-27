<?php
/*
 * CONSUMPTION PROFILES AND THE RECALCULATION OF THE EQUIPMENTS WITH THEM
 *
 * The section of the configuration page that holds the two profiles (cold and hot season) and the button of the recalculation of the
 * consumptions of the equipments with them. It is included by plugin_info/configuration.php, inside its form, and loads its own script
 * (desktop/js/profile.js). The logic is in core/class/energyProfile.class.php (the profiles) and energyProfileUpdate.class.php (the
 * recalculation), the calls go through the "profile*" actions of core/ajax/energy.ajax.php.
 */
if (!isConnect('admin')) {
	return;
}
?>
<legend><i class="fas fa-chart-pie"></i> {{Profils de consommation}}</legend>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Saison froide}} <sup><i class="fas fa-question-circle tooltips" title="{{Mois de la saison froide. Les autres mois forment la saison chaude. Chaque saison a son profil : la répartition de la consommation de la maison entre les équipements.}}"></i></sup></label>
    <div class="col-sm-8" id="div_profileMonths"></div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Profils}} <sup><i class="fas fa-question-circle tooltips" title="{{Deux types d'équipement. Fixe : une consommation en kWh par jour, la même chaque jour (frigo, box). Variable : une part en % de ce que le total de la journée laisse une fois les équipements fixes retirés (radiateur), pour chaque saison. Ce qui n'est attribué à aucun équipement est « Non mesuré ». Avec un tarif HP/HC, la part d'heures creuses (HC) de chaque équipement donne son HC, son HP et son coût ; avec un tarif fixe, ces colonnes n'apparaissent pas et le coût est la consommation × le tarif fixe.}}"></i></sup></label>
    <div class="col-sm-8">
        <table class="table table-condensed" id="table_profile">
            <thead><tr><th>{{Équipement}} <sup><i class="fas fa-question-circle tooltips" title="{{L'équipement energy d'électricité (le compteur total n'y est pas). Il est identifié par son numéro interne : le renommer ne change rien au profil.}}"></i></sup></th><th>{{Type}} <sup><i class="fas fa-question-circle tooltips" title="{{Fixe : l'équipement consomme la même quantité chaque jour (frigo, box, veille). Variable : sa consommation change d'un jour à l'autre (radiateur, chauffe-eau), il reçoit une part de ce que le total de la journée laisse une fois les équipements fixes retirés.}}"></i></sup></th><th>{{Valeur fixe (kWh/jour)}} <sup><i class="fas fa-question-circle tooltips" title="{{Pour un équipement Fixe seulement : sa consommation de chaque jour, en kWh (0,3 kWh = 12 W en continu ; un frigo : environ 1 kWh). C'est la même valeur toute l'année. Obligatoire pour un équipement Fixe ; grisé pour un Variable.}}"></i></sup></th><th>{{Saison froide (%)}} <sup><i class="fas fa-question-circle tooltips" title="{{Pour un équipement Variable seulement : sa part, en %, de ce que le total de la journée laisse après les équipements fixes, les jours de la saison froide. 0 = il ne consomme rien cette saison (un radiateur en été). La somme des équipements ne dépasse pas 100 % : le reste est « Non mesuré ».}}"></i></sup></th><th>{{Saison chaude (%)}} <sup><i class="fas fa-question-circle tooltips" title="{{La même part, pour les jours de la saison chaude. Un chauffe-eau garde une part en été (les douches) ; un radiateur est à 0.}}"></i></sup></th><th class="profileHcColumn">{{HC saison froide (%)}} <sup><i class="fas fa-question-circle tooltips" title="{{Avec une tarification HP/HC seulement : la part de la consommation de l'équipement qui tombe en heures creuses (HC) les jours de la saison froide, le reste est en heures pleines (HP). 100 = tout en HC (chauffe-eau asservi aux heures creuses), 50 = moitié-moitié, 0 ou vide = tout en HP. Sans effet avec une tarification fixe : le tarif du jour décide.}}"></i></sup></th><th class="profileHcColumn">{{HC saison chaude (%)}} <sup><i class="fas fa-question-circle tooltips" title="{{La même part d'heures creuses, pour les jours de la saison chaude. Vide = 0 : tout en heures pleines.}}"></i></sup></th></tr></thead>
            <tbody></tbody>
            <tfoot></tfoot>
        </table>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Jour aberrant}} <sup><i class="fas fa-question-circle tooltips" title="{{Un équipement a un jour aberrant quand sa valeur dépasse sa valeur du profil de plus de ce pourcentage, ou quand la somme des équipements du profil dépasse le total de la journée (il l'est alors pour tous les équipements).}}"></i></sup></label>
    <div class="col-sm-8 form-inline">
        <div class="input-group" style="width:230px">
            <span class="input-group-addon">{{Fixe}}</span>
            <input class="form-control input-sm" id="in_profileToleranceFixed" type="number" min="0" step="10"/>
            <span class="input-group-addon">%</span>
        </div>
        <div class="input-group" style="width:230px">
            <span class="input-group-addon">{{Variable}}</span>
            <input class="form-control input-sm" id="in_profileToleranceVariable" type="number" min="0" step="10"/>
            <span class="input-group-addon">%</span>
        </div>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label"></label>
    <div class="col-sm-8">
        <a class="btn btn-success btn-sm" id="bt_profileSave"><i class="fas fa-save"></i> {{Enregistrer les profils}}</a>
        <a class="btn btn-default btn-sm" id="bt_profileNormalize"><i class="fas fa-equals"></i> {{Ramener à 100 %}}</a>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Calculer depuis une plage}} <sup><i class="fas fa-question-circle tooltips" title="{{Sur une plage de jours caractéristiques de la saison choisie, détecte les équipements fixes (peu de variation d'un jour à l'autre) et leur valeur en kWh/jour, puis calcule la part des équipements variables et la part d'heures creuses de chaque équipement. Les jours où les équipements dépassent le total sont écartés. Le résultat remplit le tableau ; vous pouvez tout corriger à la main avant d'enregistrer.}}"></i></sup></label>
    <div class="col-sm-8 form-inline">
        <input class="form-control input-sm" id="in_profileFrom" type="date"/> →
        <input class="form-control input-sm" id="in_profileTo" type="date"/>
        <select class="form-control input-sm" id="sel_profileSeason"><option value="cold">{{Saison froide}}</option><option value="hot">{{Saison chaude}}</option></select>
        <a class="btn btn-default btn-sm" id="bt_profileAnalyze"><i class="fas fa-calculator"></i> {{Calculer}}</a>
        <div id="div_profileAnalysis" style="margin-top:8px"></div>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Recalcul des consommations}} <sup><i class="fas fa-question-circle tooltips" title="{{Réécrit dans l'historique la consommation des équipements du profil sur une plage de jours, avec le profil de la saison de chaque jour. Le total de la journée doit déjà être connu (Enedis ou index Linky), sinon le jour est ignoré. Une sauvegarde est faite avant l'écriture ; elle se restaure depuis la liste des sauvegardes du rattrapage.}}"></i></sup></label>
    <div class="col-sm-8">
        <a class="btn btn-warning btn-sm" id="bt_profileUpdate"><i class="fas fa-sync"></i> {{Recalculer les consommations}}</a>
    </div>
</div>
<div class="form-group">
    <label class="col-sm-2 control-label">{{Anneau}}</label>
    <div class="col-sm-8">
        <div class="btn-group" id="div_profileView">
            <a class="btn btn-default btn-sm active" data-view="year">{{Annuelle}}</a>
            <a class="btn btn-default btn-sm" data-view="cold">{{Saison froide}}</a>
            <a class="btn btn-default btn-sm" data-view="hot">{{Saison chaude}}</a>
        </div>
        <div id="div_profileChart" style="height:300px"></div>
        <div id="div_profileList"></div>
    </div>
</div>
<?php include_file('desktop', 'profile', 'js', 'energy');?>
