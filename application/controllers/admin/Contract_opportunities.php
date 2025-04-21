<?php

use app\services\imap\Imap;
use app\services\LeadProfileBadges;
use app\services\leads\LeadsKanban;
use app\services\imap\ConnectionErrorException;
use Ddeboer\Imap\Exception\MailboxDoesNotExistException;

header('Content-Type: text/html; charset=utf-8');
defined('BASEPATH') or exit('No direct script access allowed');

class Contract_opportunities extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('contract_opportunities_model');
    }

    /* List all contract opportunities */
    public function index($id = '')
    {
        close_setup_menu();

        if (!is_staff_member()) {
            access_denied('Contract Opportunities');
        }

        $data['switch_kanban'] = true;

        if ($this->session->userdata('contract_opportunities_kanban_view') == 'true') {
            $data['switch_kanban'] = false;
            $data['bodyclass']     = 'contract_opportunities-kan-ban';
        }

        $data['contract_opportunity_id'] = $id;
        $data['isKanBan']      = $this->session->userdata('contract_opportunities_kanban_view') == 'true';

        $data['staff'] = $this->staff_model->get('', ['active' => 1]);

        if ($data['isKanBan']) {
            $this->load->model('pipeline_model');
            $data['statuses'] = $this->contract_opportunities_model->get_status();
            $data['statuses'] = array_merge($data['statuses'], $this->pipeline_model->getPipelineColumns('contract_opportunities'));
            $data['summary']  = get_contract_opportunities_summary();
            $this->load->view('admin/contract_opportunities/kan-ban', $data);
        } else {
            $data['summary'] = get_contract_opportunities_summary();

            $this->load->view('admin/contract_opportunities/manage_contract_opportunities', $data);
        }
    }

    public function table()
    {
        if (!is_staff_member()) {
            ajax_access_denied();
        }
        $this->app->get_table_data('contract_opportunities');
    }

    public function kanban()
    {
        if (!is_staff_member()) {
            ajax_access_denied();
        }
        $data['statuses'] = $this->contract_opportunities_model->get_status();

        $this->load->model('pipeline_model');
        $data['statuses'] = array_merge($data['statuses'], $this->pipeline_model->getPipelineColumns('contract_opportunities'));

        echo $this->load->view('admin/contract_opportunities/kan-ban_inner', $data, true);
    }

    /* Add or update contract opportunity */
    public function contract_opportunity($id = '')
    {
        if (!is_staff_member() || ($id != '' && !$this->contract_opportunities_model->staff_can_access_contract_opportunity($id))) {
            ajax_access_denied();
        }

        if ($this->input->post()) {
            if ($id == '') {
                $id      = $this->contract_opportunities_model->add($this->input->post());
                $message = $id ? _l('added_successfully', _l('contract_opportunity')) : '';

                echo json_encode([
                    'success'  => $id ? true : false,
                    'id'       => $id,
                    'message'  => $message,
                    'leadView' => $id ? $this->_get_contract_opportunity_data($id) : [],
                ]);
            } else {
                $emailOriginal   = $this->db->select('email')->where('id', $id)->get(db_prefix() . 'contract_opportunities')->row()->email;
                $proposalWarning = false;
                $message         = '';
                $success         = $this->contract_opportunities_model->update($this->input->post(), $id);

                if ($success) {
                    $emailNow = $this->db->select('email')->where('id', $id)->get(db_prefix() . 'contract_opportunities')->row()->email;

                    $proposalWarning = (total_rows(db_prefix() . 'proposals', [
                        'rel_type' => 'contract_opportunity',
                        'rel_id'   => $id, ]) > 0 && ($emailOriginal != $emailNow) && $emailNow != '') ? true : false;

                    $message = _l('updated_successfully', _l('contract_opportunity'));
                }
                echo json_encode([
                    'success'          => $success,
                    'message'          => $message,
                    'id'               => $id,
                    'proposal_warning' => $proposalWarning,
                    'leadView'         => $this->_get_contract_opportunity_data($id),
                ]);
            }
            die;
        }

        echo json_encode([
            'leadView' => $this->_get_contract_opportunity_data($id),
        ]);
    }

    private function _get_contract_opportunity_data($id = '')
    {
        $reminder_data         = '';
        $data['contract_opportunity_id'] = $id;
        $data['base_currency'] = get_base_currency();

        if (is_numeric($id)) {
            $contract_opportunity = $this->contract_opportunities_model->get($id);

            if (!$contract_opportunity) {
                header('HTTP/1.0 404 Not Found');
                echo _l('contract_opportunity_not_found');
                die;
            }

            if (staff_can('view', 'contract_opportunities') || $contract_opportunity->assigned == get_staff_user_id() || $contract_opportunity->addedfrom == get_staff_user_id() || $contract_opportunity->is_public == 1) {
                $data['contract_opportunity'] = $contract_opportunity;
                $data['mail_template'] = get_mail_template_data($data['contract_opportunity']->email, 'contract_opportunity');

                $data['statuses'] = $this->contract_opportunities_model->get_status();
                $data['sources']  = $this->contract_opportunities_model->get_source();

                if (is_email_template_active('contract-opportunity-web-form-submitted')) {
                    $data['lead_form_submitted'] = true;
                }

                $data['members'] = $this->staff_model->get('', ['active' => 1]);

                $data['reminder_title'] = _l('contract_opportunity_set_reminder_title');
                $data['reminder_description'] = _l('contract_opportunity_set_reminder_description');

                $this->load->view('admin/contract_opportunities/contract_opportunity', $data);
            } else {
                ajax_access_denied();
            }
        } else {
            $data['statuses'] = $this->contract_opportunities_model->get_status();
            $data['sources']  = $this->contract_opportunities_model->get_source();
            $data['members']  = $this->staff_model->get('', ['active' => 1]);
            $this->load->view('admin/contract_opportunities/contract_opportunity', $data);
        }
    }

    public function leads_kanban_load_more()
    {
        if (!is_staff_member()) {
            ajax_access_denied();
        }

        $status = $this->input->get('status');
        $page   = $this->input->get('page');

        $this->db->where('id', $status);
        $status = $this->db->get(db_prefix() . 'contract_opportunities_status')->row_array();

        $leads = (new LeadsKanban($status['id']))
        ->search($this->input->get('search'))
        ->sortBy(
            $this->input->get('sort_by'),
            $this->input->get('sort')
        )
        ->page($page)->get();

        foreach ($leads as $lead) {
            $this->load->view('admin/contract_opportunities/_kan_ban_card', [
                'lead'   => $lead,
                'status' => $status,
            ]);
        }
    }

    public function switch_kanban($set = 0)
    {
        if ($set == 1) {
            $set = 'true';
        } else {
            $set = 'false';
        }
        $this->session->set_userdata([
            'contract_opportunities_kanban_view' => $set,
        ]);
        redirect($_SERVER['HTTP_REFERER']);
    }

    /* Delete contract opportunity from database */
    public function delete($id)
    {
        if (!$id) {
            redirect(admin_url('contract_opportunities'));
        }

        if (!staff_can('delete', 'contract_opportunities')) {
            access_denied('Delete Lead');
        }

        $response = $this->contract_opportunities_model->delete($id);
        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', _l('is_referenced', _l('contract_opportunity_lowercase')));
        } elseif ($response === true) {
            set_alert('success', _l('deleted', _l('contract_opportunity')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('contract_opportunity_lowercase')));
        }
        $ref = $_SERVER['HTTP_REFERER'];

        if (!$ref || strpos($ref, 'contract_opportunities/contract_opportunity') !== false) {
            redirect(admin_url('contract_opportunities'));
        }

        redirect($ref);
    }

    public function mark_as_lost($id)
    {
        if (!is_staff_member() || !$this->contract_opportunities_model->staff_can_access_contract_opportunity($id)) {
            ajax_access_denied();
        }
        $message = '';
        $success = $this->contract_opportunities_model->mark_as_lost($id);
        if ($success) {
            $message = _l('contract_opportunity_marked_as_lost');
        }
        echo json_encode([
            'success'  => $success,
            'message'  => $message,
            'leadView' => $this->_get_contract_opportunity_data($id),
            'id'       => $id,
        ]);
    }

    public function unmark_as_lost($id)
    {
        if (!is_staff_member() || !$this->contract_opportunities_model->staff_can_access_contract_opportunity($id)) {
            ajax_access_denied();
        }
        $message = '';
        $success = $this->contract_opportunities_model->unmark_as_lost($id);
        if ($success) {
            $message = _l('contract_opportunity_unmarked_as_lost');
        }
        echo json_encode([
            'success'  => $success,
            'message'  => $message,
            'leadView' => $this->_get_contract_opportunity_data($id),
            'id'       => $id,
        ]);
    }

    public function mark_as_junk($id)
    {
        if (!is_staff_member() || !$this->contract_opportunities_model->staff_can_access_contract_opportunity($id)) {
            ajax_access_denied();
        }
        $message = '';
        $success = $this->contract_opportunities_model->mark_as_junk($id);
        if ($success) {
            $message = _l('contract_opportunity_marked_as_junk');
        }
        echo json_encode([
            'success'  => $success,
            'message'  => $message,
            'leadView' => $this->_get_contract_opportunity_data($id),
            'id'       => $id,
        ]);
    }

    public function unmark_as_junk($id)
    {
        if (!is_staff_member() || !$this->contract_opportunities_model->staff_can_access_contract_opportunity($id)) {
            ajax_access_denied();
        }
        $message = '';
        $success = $this->contract_opportunities_model->unmark_as_junk($id);
        if ($success) {
            $message = _l('contract_opportunity_unmarked_as_junk');
        }
        echo json_encode([
            'success'  => $success,
            'message'  => $message,
            'leadView' => $this->_get_contract_opportunity_data($id),
            'id'       => $id,
        ]);
    }

    public function add_activity()
    {
        $leadid = $this->input->post('leadid');
        if (!is_staff_member() || !$this->contract_opportunities_model->staff_can_access_contract_opportunity($leadid)) {
            ajax_access_denied();
        }
        if ($this->input->post()) {
            $message = $this->input->post('activity');
            $aId     = $this->contract_opportunities_model->log_contract_opportunity_activity($leadid, $message);
            if ($aId) {
                $this->db->where('id', $aId);
                $this->db->update(db_prefix() . 'contract_opportunity_activity_log', ['custom_activity' => 1]);
            }
            echo json_encode(['leadView' => $this->_get_contract_opportunity_data($leadid), 'id' => $leadid]);
        }
    }

    public function get_convert_data($id)
    {
        if (!is_staff_member() || !$this->contract_opportunities_model->staff_can_access_contract_opportunity($id)) {
            ajax_access_denied();
        }
        if (is_gdpr() && get_option('gdpr_enable_consent_for_contacts') == '1') {
            $this->load->model('gdpr_model');
            $data['purposes'] = $this->gdpr_model->get_consent_purposes($id, 'contract_opportunity');
        }
        $data['contract_opportunity'] = $this->contract_opportunities_model->get($id);
        $this->load->view('admin/contract_opportunities/convert_to_customer', $data);
    }

    /**
     * Convert contract opportunity to client
     * @since  Version 1.0.1
     * @return mixed
     */
    public function convert_to_customer()
    {
        if (!is_staff_member()) {
            access_denied('Contract Opportunity Convert to Customer');
        }

        if ($this->input->post()) {
            $data = $this->input->post();
            $data['password'] = $this->input->post('password', false);

            $original_contract_opportunity_id = $data['original_contract_opportunity_id'];
            unset($data['original_contract_opportunity_id']);

            if (isset($data['transfer_notes'])) {
                $notes = $this->misc_model->get_notes($original_contract_opportunity_id, 'contract_opportunity');
                unset($data['transfer_notes']);
            }

            if (isset($data['transfer_consent'])) {
                $this->load->model('gdpr_model');
                $consents = $this->gdpr_model->get_consents(['contract_opportunity_id' => $original_contract_opportunity_id]);
                unset($data['transfer_consent']);
            }

            if (isset($data['merge_with_contact'])) {
                $contactid = $data['merge_with_contact'];
                unset($data['merge_with_contact']);
            }

            $id = $this->contract_opportunities_model->convert_to_customer($data, $original_contract_opportunity_id);

            if ($id) {
                if (isset($notes)) {
                    foreach ($notes as $note) {
                        $this->db->insert(db_prefix() . 'notes', [
                            'rel_id'         => $id,
                            'rel_type'       => 'customer',
                            'dateadded'      => $note['dateadded'],
                            'addedfrom'      => $note['addedfrom'],
                            'description'    => $note['description'],
                            'date_contacted' => $note['date_contacted'],
                        ]);
                    }
                }
                if (isset($consents)) {
                    foreach ($consents as $consent) {
                        unset($consent['id']);
                        unset($consent['contract_opportunity_id']);
                        $consent['contact_id'] = isset($contactid) ? $contactid : $id;
                        $this->gdpr_model->add_consent($consent);
                    }
                }

                hooks()->do_action('contract_opportunity_converted_to_customer', ['contract_opportunity_id' => $original_contract_opportunity_id, 'customer_id' => $id]);
            }

            if ($id) {
                set_alert('success', _l('contract_opportunity_converted_to_client'));
                redirect(admin_url('clients/client/' . $id));
            } else {
                set_alert('warning', _l('contract_opportunity_converted_to_client_fail'));
            }

            if ($original_contract_opportunity_id) {
                redirect(admin_url('contract_opportunities/contract_opportunity/' . $original_contract_opportunity_id));
            } else {
                redirect(admin_url('contract_opportunities'));
            }
        }
    }

    /* Used in kanban when dragging and mark as */
    public function update_contract_opportunity_status()
    {
        if ($this->input->post() && $this->input->is_ajax_request()) {
            if (!is_staff_member()) {
                ajax_access_denied();
            }

            $this->contract_opportunities_model->update_contract_opportunity_status($this->input->post());
        }
    }

    public function update_kan_ban_sort()
    {
        if (!staff_can('view', 'contract_opportunities')) {
            ajax_access_denied();
        }
        if ($this->input->post()) {
            $this->contract_opportunities_model->update_kan_ban_sort($this->input->post());
        }
    }

    public function download_files($contract_opportunity_id)
    {
        if (!is_staff_member() || !$this->contract_opportunities_model->staff_can_access_contract_opportunity($contract_opportunity_id)) {
            ajax_access_denied();
        }

        $files = $this->contract_opportunities_model->get_contract_opportunity_attachments($contract_opportunity_id);

        if (count($files) == 0) {
            redirect($_SERVER['HTTP_REFERER']);
        }

        $path = get_upload_path_by_type('contract_opportunity') . $contract_opportunity_id;
        $zip  = new ZipArchive();
        $zipFileName = slug_it(get_contract_opportunity_name_by_id($contract_opportunity_id)) . '-files.zip';
        $zip->open($zipFileName, ZipArchive::CREATE);

        foreach ($files as $file) {
            $zip->addFile($path . '/' . $file['file_name'], $file['file_name']);
        }

        $zip->close();

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($zipFileName) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($zipFileName));
        flush(); // Flush system output buffer
        readfile($zipFileName);
        @unlink($zipFileName);
        exit;
    }
}
