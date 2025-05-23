<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?= form_open($formUrl, ['id' => 'schedule_send_form']); ?>
<div class="col-md-12 no-padding animated fadeIn">
    <div class="panel_s">
        <div class="panel-body">
            <h4 class="tw-my-0 tw-font-bold">
                <?= e(_l('schedule_email_for', format_estimate_number($estimate->id))); ?>
            </h4>
            <hr class="hr-panel-separator" />
            <div class="row">
                <div class="col-md-12">
                    <div class="checkbox checkbox-primary">
                        <input type="checkbox" name="attach_pdf" id="attach_pdf"
                            <?= isset($schedule) && $schedule->attach_pdf || ! isset($schedule) ? ' checked' : ''; ?>>
                        <label
                            for="attach_pdf"><?= _l('estimate_send_to_client_attach_pdf'); ?></label>
                    </div>
                    <hr />
                    <div class="form-group">
                        <?php
                        if ($template_disabled) {
                            echo '<div class="alert alert-danger">';
                            echo 'The email template <b><a href="' . admin_url('emails/email_template/' . $template_id) . '" target="_blank" class="alert-link">' . $template_system_name . '</a></b> is disabled. Click <a href="' . admin_url('emails/email_template/' . $template_id) . '" class="alert-link" target="_blank">here</a> to enable the email template in order to be sent successfully.';
                            echo '</div>';
                        }
$selected = [];
$contacts = $this->clients_model->get_contacts($estimate->clientid, ['active' => 1, 'estimate_emails' => 1]);

if (! isset($schedule)) {
    foreach ($contacts as $contact) {
        array_push($selected, $contact['id']);
    }
} else {
    $selected = explode(',', $schedule->contacts);
}

if (count($selected) == 0) {
    echo '<p class="text-danger">' . _l('sending_email_contact_permissions_warning', _l('customer_permission_estimate')) . '</p><hr />';
}

echo render_select('sent_to[]', $contacts, ['id', 'email', 'firstname,lastname'], 'invoice_estimate_sent_to_email', $selected, ['multiple' => true], [], '', '', false);
?>
                    </div>
                    <?= render_input('cc', 'CC', isset($schedule) ? $schedule->cc : ''); ?>
                    <?= render_datetime_input(
                        'scheduled_at',
                        'schedule_date',
                        _d($date)
                    ); ?>
                </div>
            </div>
        </div>
        <div class="panel-footer text-right">
            <a href="#" class="btn btn-danger"
                onclick="init_estimate(<?= e($estimate->id); ?>); return false;">
                <?= _l('cancel'); ?>
            </a>
            <button type="submit" autocomplete="off"
                data-loading-text="<?= _l('wait_text'); ?>"
                data-form="#schedule_send_form" class="btn btn-success">
                <?= _l('schedule'); ?>
            </button>
        </div>
    </div>
</div>
<?= form_close(); ?>
<script>
    $(function() {
        init_selectpicker();
        init_datepicker();
        appValidateForm($('#schedule_send_form'), {
            'sent_to[]': 'required',
            scheduled_at: 'required'
        });
    });
</script>