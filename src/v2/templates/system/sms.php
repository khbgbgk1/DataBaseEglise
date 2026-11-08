<?php

use EcclesiaCRM\dto\SystemConfig;
use EcclesiaCRM\dto\SystemURLs;

//Set the page title
include SystemURLs::getDocumentRoot() . '/Include/Header.php';
?>
<div class="row">
    <div class="col-12 pb-4">
        <div class="alert alert-light border shadow-sm mb-0 d-flex flex-column flex-md-row align-items-md-center justify-content-between">
            <div class="pr-md-3">
                <h2 class="h4 mb-1"><i class="fas fa-sms text-primary mr-2"></i><?= _("SMS Settings") ?></h2>
                <p class="mb-0 text-muted"><?= _("Configure the Vonage (Nexmo) SMS service and test that it is working.") ?></p>
            </div>
            <span class="badge badge-<?= SystemConfig::hasValidSMSServerSettings() ? "success" : "danger" ?> mt-2 mt-md-0 align-self-md-center">
                <i class="fas fa-<?= SystemConfig::hasValidSMSServerSettings() ? "check" : "times" ?> mr-1"></i>
                <?= SystemConfig::hasValidSMSServerSettings() ? _("SMS Settings Valid") : _("SMS Settings Invalid") ?>
            </span>
        </div>
    </div>

    <div class="col-12 col-lg-6 pb-4">
        <div class="card card-outline card-primary shadow-sm mb-0 h-100">
            <div class="card-header border-0 py-2">
                <h3 class="card-title d-block float-none mb-0"><i class="fas fa-cogs mr-2"></i><?= _("SMS Account Configuration") ?></h3>
                <p class="small text-muted mb-0 mt-1"><?= _("Get your API Key / Secret from the Vonage (Nexmo) dashboard. The From number must be an active SMS number in your account.") ?></p>
            </div>
            <div class="card-body">
                <form id="sms-settings-form">
                    <div class="form-group">
                        <label for="smsApiKey"><?= _("API Key") ?> : sNexmoAPIKey</label>
                        <input type="text" id="smsApiKey" name="apiKey" class="form-control form-control-sm"
                               value="<?= htmlspecialchars(SystemConfig::getValue("sNexmoAPIKey"), ENT_QUOTES) ?>">
                    </div>
                    <div class="form-group">
                        <label for="smsApiSecret"><?= _("API Secret") ?> : sNexmoAPISecret</label>
                        <input type="password" id="smsApiSecret" name="apiSecret" class="form-control form-control-sm" autocomplete="off"
                               value="<?= htmlspecialchars(SystemConfig::getValue("sNexmoAPISecret"), ENT_QUOTES) ?>">
                    </div>
                    <div class="form-group">
                        <label for="smsFromNumber"><?= _("From Number") ?> : sNexmoFromNumber</label>
                        <input type="text" id="smsFromNumber" name="fromNumber" class="form-control form-control-sm"
                               placeholder="+33000000000"
                               value="<?= htmlspecialchars(SystemConfig::getValue("sNexmoFromNumber"), ENT_QUOTES) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" id="saveSMSSettings">
                        <i class="fas fa-save mr-1"></i><?= _("Save Settings") ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6 pb-4">
        <div class="card card-outline card-info shadow-sm mb-0 h-100">
            <div class="card-header border-0 py-2">
                <h3 class="card-title d-block float-none mb-0"><i class="fas fa-paper-plane mr-2"></i><?= _("Send a Test SMS") ?></h3>
                <p class="small text-muted mb-0 mt-1"><?= _("Send a short test message to a phone number to check that the SMS service works.") ?></p>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="smsTestNumber"><?= _("Phone number (international format)") ?></label>
                    <input type="text" id="smsTestNumber" class="form-control form-control-sm" placeholder="+33000000000">
                </div>
                <button type="button" class="btn btn-info btn-sm" id="testSMS">
                    <i class="fas fa-paper-plane mr-1"></i><?= _("Send Test SMS") ?>
                </button>
                <p id="smsTestResult" class="alert alert-light border mt-3 mb-0" aria-live="polite"
                   data-loading-message="<?= _("Testing connection .....") ?>"></p>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= SystemURLs::getCSPNonce() ?>">
    $(function() {
        const smsTestResult = $("#smsTestResult");

        $("#sms-settings-form").on("submit", function(event) {
            event.preventDefault();
            window.CRM.APIRequest({
                method: 'POST',
                path: 'system/saveSMSSettings',
                data: JSON.stringify({
                    apiKey: $("#smsApiKey").val(),
                    apiSecret: $("#smsApiSecret").val(),
                    fromNumber: $("#smsFromNumber").val()
                })
            }, function(data) {
                if (data.status === "success") {
                    window.CRM.showGlobalMessage(i18next.t("Setting saved"), "success");
                } else {
                    window.CRM.showGlobalMessage(i18next.t("Saving failed"), "danger");
                }
            });
        });

        $("#testSMS").on("click", function() {
            smsTestResult.removeClass("alert-success alert-danger").addClass("alert-light");
            smsTestResult.text(smsTestResult.data("loading-message"));

            window.CRM.APIRequest({
                method: 'POST',
                path: 'system/testSMS',
                data: JSON.stringify({ to: $("#smsTestNumber").val() })
            }, function(data) {
                if (data.success == true) {
                    smsTestResult.removeClass("alert-light alert-danger").addClass("alert-success").html(data.result);
                } else {
                    smsTestResult.removeClass("alert-light alert-success").addClass("alert-danger").html(data.error);
                }
            });
        });
    });
</script>

<?php require SystemURLs::getDocumentRoot() . '/Include/Footer.php'; ?>