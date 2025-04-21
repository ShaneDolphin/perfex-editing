<?php

use app\services\AbstractKanban;

defined('BASEPATH') or exit('No direct script access allowed');

class Contract_opportunities_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get contract opportunity
     * @param  string $id Optional - contract_opportunity id
     * @return mixed
     */
    public function get($id = '', $where = [])
    {
        $this->db->select('*,' . db_prefix() . 'contract_opportunities.name, ' . db_prefix() . 'contract_opportunities.id,' . db_prefix() . 'contract_opportunities_status.name as status_name,' . db_prefix() . 'contract_opportunities_sources.name as source_name');
        $this->db->join(db_prefix() . 'contract_opportunities_status', db_prefix() . 'contract_opportunities_status.id=' . db_prefix() . 'contract_opportunities.status', 'left');
        $this->db->join(db_prefix() . 'contract_opportunities_sources', db_prefix() . 'contract_opportunities_sources.id=' . db_prefix() . 'contract_opportunities.source', 'left');

        $this->db->where($where);
        if (is_numeric($id)) {
            $this->db->where(db_prefix() . 'contract_opportunities.id', $id);
            $contract_opportunity = $this->db->get(db_prefix() . 'contract_opportunities')->row();
            if ($contract_opportunity) {
                if ($contract_opportunity->from_form_id != 0) {
                    $this->db->select('name');
                    $this->db->from(db_prefix() . 'web_to_lead');
                    $this->db->where('id', $contract_opportunity->from_form_id);
                    $form = $this->db->get()->row();
                    if ($form) {
                        $contract_opportunity->form_data = $form;
                    }
                }

                $contract_opportunity->attachments = $this->get_contract_opportunity_attachments($id);
                $contract_opportunity->public_url  = site_url('contract_opportunities/public/' . $id . '/' . $contract_opportunity->hash);

                return $contract_opportunity;
            }

            return null;
        }

        $this->db->order_by('name', 'asc');

        return $this->db->get(db_prefix() . 'contract_opportunities')->result_array();
    }

    /**
     * Update contract opportunity status
     * @param  array  $data contract opportunity data
     * @return boolean
     */
    public function update_contract_opportunity_status($data)
    {
        $this->db->select('status');
        $this->db->where('id', $data['contractopportunityid']);
        $_old = $this->db->get(db_prefix() . 'contract_opportunities')->row();

        $old_status = '';

        if ($_old) {
            $old_status = $this->get_status($_old->status);
            if ($old_status) {
                $old_status = $old_status->name;
            }
        }

        $affectedRows = 0;
        $current_status = $this->get_status($data['status'])->name;

        $this->db->where('id', $data['contractopportunityid']);
        $this->db->update(db_prefix() . 'contract_opportunities', [
            'status' => $data['status'],
        ]);

        if ($this->db->affected_rows() > 0) {
            $affectedRows++;
        }

        if ($current_status != $old_status && $affectedRows > 0) {
            $this->db->where('id', $data['contractopportunityid']);
            $this->db->update(db_prefix() . 'contract_opportunities', [
                'last_status_change' => date('Y-m-d H:i:s'),
            ]);
            $affectedRows++;
        }

        if ($affectedRows > 0) {
            $this->log_contract_opportunity_activity($data['contractopportunityid'], 'not_contract_opportunity_activity_status_updated', false, serialize([
                get_staff_full_name(),
                $old_status,
                $current_status,
            ]));

            return true;
        }

        return false;
    }

    /**
     * Update kan ban contract opportunity status
     * @param  array $data contract opportunity data
     * @return boolean
     */
    public function update_kan_ban_sort($data)
    {
        extract($data);
        foreach ($data['order'] as $order_data) {
            if ($order_data['contractopportunity_id'] != '') {
                $this->db->where('id', $order_data['contractopportunity_id']);
                $this->db->update(db_prefix() . 'contract_opportunities', [
                    'kanban_order' => $order_data['order'],
                ]);
            }
        }
    }

    /**
     * Get contract opportunity attachments
     * @since Version 1.0.4
     * @param  mixed $id contract opportunity id
     * @return array
     */
    public function get_contract_opportunity_attachments($id = '', $attachment_id = '')
    {
        if (is_numeric($attachment_id)) {
            $this->db->where('id', $attachment_id);

            return $this->db->get(db_prefix() . 'files')->row();
        }
        $this->db->where('rel_id', $id);
        $this->db->where('rel_type', 'contract_opportunity');
        $this->db->order_by('dateadded', 'DESC');

        return $this->db->get(db_prefix() . 'files')->result_array();
    }

    /**
     * Convert contract opportunity to client
     * @param  mixed $id contract opportunity id
     * @return mixed     client ID if success, false if not
     */
    public function convert_to_customer($data, $id)
    {
        $this->load->model('clients_model');
        $default_country  = get_option('customer_default_country');
        $default_currency = get_option('default_currency');
        $default_groups   = [];

        if (is_array(get_option('customer_default_group'))) {
            $default_groups = get_option('customer_default_group');
        } elseif (get_option('customer_default_group') != 0) {
            $default_groups[] = get_option('customer_default_group');
        }

        $data['is_primary'] = 1;
        $data['password']   = app_generate_hash();

        if (isset($data['transfer_notes'])) {
            $notes = $this->misc_model->get_notes($id, 'contract_opportunity');
            unset($data['transfer_notes']);
        }

        if (isset($data['transfer_consent'])) {
            $this->load->model('gdpr_model');
            $consents = $this->gdpr_model->get_consents(['contract_opportunity_id' => $id]);
            unset($data['transfer_consent']);
        }

        $data['billing_street']  = $data['address'];
        $data['billing_city']    = $data['city'];
        $data['billing_state']   = $data['state'];
        $data['billing_zip']     = $data['zip'];
        $data['billing_country'] = $data['country'] != 0 ? $data['country'] : $default_country;
        $data['country']         = $data['country'] != 0 ? $data['country'] : $default_country;

        $data['is_active'] = 1;
        if (isset($data['default_language']) && $data['default_language'] == '') {
            unset($data['default_language']);
        }

        if (isset($data['groups'])) {
            $groups = $data['groups'];
            unset($data['groups']);
        }

        $data['datecreated'] = date('Y-m-d H:i:s');
        $data['from_form_id'] = 0;

        $data['addedfrom'] = get_staff_user_id();

        $data['website'] = $data['website'] != '' ? $data['website'] : '';

        $data['address'] = $data['address'] != '' ? $data['address'] : '';

        $data['city'] = $data['city'] != '' ? $data['city'] : '';
        $data['state'] = $data['state'] != '' ? $data['state'] : '';

        $data['zip'] = $data['zip'] != '' ? $data['zip'] : '';

        $data['billing_street'] = $data['billing_street'] != '' ? $data['billing_street'] : '';
        $data['billing_city'] = $data['billing_city'] != '' ? $data['billing_city'] : '';
        $data['billing_state'] = $data['billing_state'] != '' ? $data['billing_state'] : '';
        $data['billing_zip'] = $data['billing_zip'] != '' ? $data['billing_zip'] : '';

        $data['client_id'] = $this->clients_model->add($data, true);

        if ($data['client_id']) {
            if (isset($groups) && is_array($groups)) {
                $this->db->insert_batch(db_prefix() . 'customer_groups', array_map(function ($group) use ($data) {
                    return [
                        'customer_id' => $data['client_id'],
                        'groupid'     => $group,
                    ];
                }, $groups));
            } elseif (count($default_groups) > 0) {
                $this->db->insert_batch(db_prefix() . 'customer_groups', array_map(function ($group) use ($data) {
                    return [
                        'customer_id' => $data['client_id'],
                        'groupid'     => $group,
                    ];
                }, $default_groups));
            }

            if (isset($notes)) {
                foreach ($notes as $note) {
                    $this->db->insert(db_prefix() . 'notes', [
                        'rel_id'         => $data['client_id'],
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
                    $consent['lead_id'] = 0;
                    $consent['client_id'] = $data['client_id'];
                    $this->gdpr_model->add_consent($consent);
                }
            }

            $this->db->where('id', $id);
            $this->db->update(db_prefix() . 'contract_opportunities', [
                'date_converted' => date('Y-m-d H:i:s'),
                'status'         => 0,
                'junk'           => 0,
                'lost'           => 0,
            ]);

            log_activity('Contract Opportunity Converted to Client [Contract OpportunityID: ' . $id . ', ClientID: ' . $data['client_id'] . ']');

            hooks()->do_action('contract_opportunity_converted_to_customer', ['contract_opportunity_id' => $id, 'customer_id' => $data['client_id']]);

            return $data['client_id'];
        }

        return false;
    }

    /**
     * Delete contract opportunity from database and all connections
     * @param  mixed $id contract opportunity id
     * @return boolean
     */
    public function delete($id)
    {
        $affectedRows = 0;

        hooks()->do_action('before_contract_opportunity_deleted', $id);

        $contract_opportunity = $this->get($id);

        $this->db->where('id', $id);
        $this->db->delete(db_prefix() . 'contract_opportunities');
        if ($this->db->affected_rows() > 0) {
            log_activity('Contract Opportunity Deleted [Deleted by: ' . get_staff_full_name() . ', ID: ' . $id . ']');

            $attachments = $this->get_contract_opportunity_attachments($id);
            foreach ($attachments as $attachment) {
                $this->delete_contract_opportunity_attachment($attachment['id']);
            }

            $this->db->where('relid', $id);
            $this->db->where('fieldto', 'contract_opportunities');
            $this->db->delete(db_prefix() . 'customfieldsvalues');

            $this->db->where('rel_id', $id);
            $this->db->where('rel_type', 'contract_opportunity');
            $this->db->delete(db_prefix() . 'notes');

            $this->db->where('rel_type', 'contract_opportunity');
            $this->db->where('rel_id', $id);
            $this->db->delete(db_prefix() . 'reminders');

            $this->db->where('rel_type', 'contract_opportunity');
            $this->db->where('rel_id', $id);
            $this->db->delete(db_prefix() . 'taggables');

            $this->db->where('rel_type', 'contract_opportunity');
            $this->db->where('rel_id', $id);
            $tasks = $this->db->get(db_prefix() . 'tasks')->result_array();
            foreach ($tasks as $task) {
                $this->tasks_model->delete_task($task['id']);
            }

            $this->db->where('rel_id', $id);
            $this->db->where('rel_type', 'contract_opportunity');
            $this->db->delete(db_prefix() . 'contract_opportunity_activity_log');

            $this->db->where('rel_id', $id);
            $this->db->where('rel_type', 'contract_opportunity');
            $this->db->delete(db_prefix() . 'consents');

            $affectedRows++;
        }
        if ($affectedRows > 0) {
            return true;
        }

        return false;
    }

    /**
     * Log contract opportunity activity
     * @param  integer $id   contract opportunity id
     * @param  string  $description activity description
     */
    public function log_contract_opportunity_activity($id, $description, $integration = false, $additional_data = '')
    {
        $log = [
            'date'            => date('Y-m-d H:i:s'),
            'description'     => $description,
            'contract_opportunity_id'      => $id,
            'staffid'         => get_staff_user_id(),
            'additional_data' => $additional_data,
            'full_name'       => get_staff_full_name(),
        ];
        if ($integration == true) {
            $log['staffid']   = 0;
            $log['full_name'] = '[CRON]';
        }

        $this->db->insert(db_prefix() . 'contract_opportunity_activity_log', $log);

        return $this->db->insert_id();
    }

    /**
     * Get contract opportunity activity log
     * @param  mixed $id contract opportunity id
     * @return array
     */
    public function get_contract_opportunity_activity_log($id)
    {
        $this->db->where('contract_opportunity_id', $id);
        $this->db->order_by('date', 'desc');

        return $this->db->get(db_prefix() . 'contract_opportunity_activity_log')->result_array();
    }

    /**
     * Delete contract opportunity attachment
     * @param  mixed $id attachment id
     * @return boolean
     */
    public function delete_contract_opportunity_attachment($id)
    {
        $attachment = $this->get_contract_opportunity_attachments('', $id);
        $deleted    = false;
        if ($attachment) {
            if (empty($attachment->external)) {
                unlink(get_upload_path_by_type('contract_opportunity') . $attachment->rel_id . '/' . $attachment->file_name);
            }
            $this->db->where('id', $attachment->id);
            $this->db->delete(db_prefix() . 'files');
            if ($this->db->affected_rows() > 0) {
                $deleted = true;
                log_activity('Contract Opportunity Attachment Deleted [ID: ' . $attachment->rel_id . ']');
            }

            if (is_dir(get_upload_path_by_type('contract_opportunity') . $attachment->rel_id)) {
                $other_attachments = list_files(get_upload_path_by_type('contract_opportunity') . $attachment->rel_id);
                if (count($other_attachments) == 0) {
                    delete_dir(get_upload_path_by_type('contract_opportunity') . $attachment->rel_id);
                }
            }
        }

        return $deleted;
    }


    /**
     * Get contract opportunity source
     * @param  mixed $id Optional - Source ID
     * @return mixed object if id passed else array
     */
    public function get_source($id = false)
    {
        if (is_numeric($id)) {
            $this->db->where('id', $id);

            return $this->db->get(db_prefix() . 'contract_opportunities_sources')->row();
        }

        $this->db->order_by('name', 'asc');

        return $this->db->get(db_prefix() . 'contract_opportunities_sources')->result_array();
    }

    /**
     * Add new contract opportunity source
     * @param mixed $data source data
     */
    public function add_source($data)
    {
        $this->db->insert(db_prefix() . 'contract_opportunities_sources', $data);
        $insert_id = $this->db->insert_id();
        if ($insert_id) {
            log_activity('New Contract Opportunity Source Added [SourceID: ' . $insert_id . ', Name: ' . $data['name'] . ']');
        }

        return $insert_id;
    }

    /**
     * Update contract opportunity source
     * @param  mixed $data source data
     * @param  mixed $id   source id
     * @return boolean
     */
    public function update_source($data, $id)
    {
        $this->db->where('id', $id);
        $this->db->update(db_prefix() . 'contract_opportunities_sources', $data);
        if ($this->db->affected_rows() > 0) {
            log_activity('Contract Opportunity Source Updated [SourceID: ' . $id . ', Name: ' . $data['name'] . ']');

            return true;
        }

        return false;
    }

    /**
     * Delete contract opportunity source from database
     * @param  mixed $id source id
     * @return mixed
     */
    public function delete_source($id)
    {
        $current = $this->get_source($id);
        if (is_reference_in_table('source', db_prefix() . 'contract_opportunities', $id)) {
            return [
                'referenced' => true,
            ];
        }
        $this->db->where('id', $id);
        $this->db->delete(db_prefix() . 'contract_opportunities_sources');
        if ($this->db->affected_rows() > 0) {
            if (get_option('contract_opportunity_default_source') == $id) {
                update_option('contract_opportunity_default_source', '');
            }
            log_activity('Contract Opportunity Source Deleted [SourceID: ' . $id . ']');

            return true;
        }

        return false;
    }


    /**
     * Get contract opportunity status
     * @param  mixed $id status id
     * @return mixed      object if id passed else array
     */
    public function get_status($id = '', $where = [])
    {
        $this->db->where($where);
        if (is_numeric($id)) {
            $this->db->where('id', $id);

            return $this->db->get(db_prefix() . 'contract_opportunities_status')->row();
        }

        $statuses = $this->app_object_cache->get('contract-opportunities-all-statuses');

        if (!$statuses) {
            $this->db->order_by('statusorder', 'asc');

            $statuses = $this->db->get(db_prefix() . 'contract_opportunities_status')->result_array();
            $this->app_object_cache->add('contract-opportunities-all-statuses', $statuses);
        }

        return $statuses;
    }

    /**
     * Add new contract opportunity status
     * @param array $data contract opportunity status data
     */
    public function add_status($data)
    {
        if (isset($data['color']) && $data['color'] == '') {
            $data['color'] = hooks()->apply_filters('default_contract_opportunity_status_color', '#757575');
        }

        if (!isset($data['statusorder'])) {
            $data['statusorder'] = total_rows(db_prefix() . 'contract_opportunities_status') + 1;
        }

        $this->db->insert(db_prefix() . 'contract_opportunities_status', $data);
        $insert_id = $this->db->insert_id();
        if ($insert_id) {
            log_activity('New Contract Opportunity Status Added [StatusID: ' . $insert_id . ', Name: ' . $data['name'] . ']');

            return $insert_id;
        }

        return false;
    }

    /**
     * Update contract opportunity status
     * @param  array $data status data
     * @param  mixed $id   status id
     * @return boolean
     */
    public function update_status($data, $id)
    {
        $this->db->where('id', $id);
        $this->db->update(db_prefix() . 'contract_opportunities_status', $data);
        if ($this->db->affected_rows() > 0) {
            log_activity('Contract Opportunity Status Updated [StatusID: ' . $id . ', Name: ' . $data['name'] . ']');

            return true;
        }

        return false;
    }

    /**
     * Delete contract opportunity status from database
     * @param  mixed $id status id
     * @return boolean
     */
    public function delete_status($id)
    {
        $current = $this->get_status($id);
        if (is_reference_in_table('status', db_prefix() . 'contract_opportunities', $id)) {
            return [
                'referenced' => true,
            ];
        }
        $this->db->where('id', $id);
        $this->db->delete(db_prefix() . 'contract_opportunities_status');
        if ($this->db->affected_rows() > 0) {
            if (get_option('contract_opportunity_default_status') == $id) {
                update_option('contract_opportunity_default_status', '');
            }
            log_activity('Contract Opportunity Status Deleted [StatusID: ' . $id . ']');

            return true;
        }

        return false;
    }

    /**
     * Mark contract opportunity as lost
     * @param  mixed $id contract opportunity id
     * @return boolean
     */
    public function mark_as_lost($id)
    {
        $this->db->select('status');
        $this->db->from(db_prefix() . 'contract_opportunities');
        $this->db->where('id', $id);
        $contract_opportunity = $this->db->get()->row();
        $this->db->where('id', $id);
        $this->db->update(db_prefix() . 'contract_opportunities', [
            'lost'               => 1,
            'status'             => 0,
            'last_status_change' => date('Y-m-d H:i:s'),
            'last_status'        => $contract_opportunity->status,
        ]);
        if ($this->db->affected_rows() > 0) {
            $this->log_contract_opportunity_activity($id, 'not_contract_opportunity_activity_marked_lost');

            log_activity('Contract Opportunity Marked as Lost [ID: ' . $id . ']');

            hooks()->do_action('contract_opportunity_marked_as_lost', $id);

            return true;
        }

        return false;
    }

    /**
     * Unmark contract opportunity as lost
     * @param  mixed $id contract opportunity id
     * @return boolean
     */
    public function unmark_as_lost($id)
    {
        $this->db->select('last_status');
        $this->db->from(db_prefix() . 'contract_opportunities');
        $this->db->where('id', $id);
        $contract_opportunity = $this->db->get()->row();

        $this->db->where('id', $id);
        $this->db->update(db_prefix() . 'contract_opportunities', [
            'lost'               => 0,
            'status'             => $contract_opportunity->last_status,
            'last_status_change' => date('Y-m-d H:i:s'),
            'last_status'        => 0,
        ]);
        if ($this->db->affected_rows() > 0) {
            $this->log_contract_opportunity_activity($id, 'not_contract_opportunity_activity_unmarked_lost');

            log_activity('Contract Opportunity Unmarked as Lost [ID: ' . $id . ']');

            return true;
        }

        return false;
    }

    /**
     * Mark contract opportunity as junk
     * @param  mixed $id contract opportunity id
     * @return boolean
     */
    public function mark_as_junk($id)
    {
        $this->db->select('status');
        $this->db->from(db_prefix() . 'contract_opportunities');
        $this->db->where('id', $id);
        $contract_opportunity = $this->db->get()->row();

        $this->db->where('id', $id);
        $this->db->update(db_prefix() . 'contract_opportunities', [
            'junk'               => 1,
            'status'             => 0,
            'last_status_change' => date('Y-m-d H:i:s'),
            'last_status'        => $contract_opportunity->status,
        ]);
        if ($this->db->affected_rows() > 0) {
            $this->log_contract_opportunity_activity($id, 'not_contract_opportunity_activity_marked_junk');

            log_activity('Contract Opportunity Marked as Junk [ID: ' . $id . ']');

            hooks()->do_action('contract_opportunity_marked_as_junk', $id);

            return true;
        }

        return false;
    }

    /**
     * Unmark contract opportunity as junk
     * @param  mixed $id contract opportunity id
     * @return boolean
     */
    public function unmark_as_junk($id)
    {
        $this->db->select('last_status');
        $this->db->from(db_prefix() . 'contract_opportunities');
        $this->db->where('id', $id);
        $contract_opportunity = $this->db->get()->row();

        $this->db->where('id', $id);
        $this->db->update(db_prefix() . 'contract_opportunities', [
            'junk'               => 0,
            'status'             => $contract_opportunity->last_status,
            'last_status_change' => date('Y-m-d H:i:s'),
            'last_status'        => 0,
        ]);
        if ($this->db->affected_rows() > 0) {
            $this->log_contract_opportunity_activity($id, 'not_contract_opportunity_activity_unmarked_junk');
            log_activity('Contract Opportunity Unmarked as Junk [ID: ' . $id . ']');

            return true;
        }

        return false;
    }
}
