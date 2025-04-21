<?php

defined('BASEPATH') or exit('No direct script access allowed');

hooks()->add_action('app_admin_head', 'contract_opportunities_app_admin_head_data');

function contract_opportunities_app_admin_head_data()
{
    ?>
    <script>
        var contractOpportunityUniqueValidationFields = <?php echo json_decode(json_encode(get_option('contract_opportunity_unique_validation'))); ?>;
        var contractOpportunityAttachmentsDropzone;
    </script>
    <?php
}

/**
 * Check if the user is contract opportunity creator
 * @since  Version 1.0.4
 * @param  mixed  $contract_opportunity_id contract_opportunityid
 * @param  mixed  $staff_id staff id (Optional)
 * @return boolean
 */

function is_contract_opportunity_creator($contract_opportunity_id, $staff_id = '')
{
    if (!is_numeric($staff_id)) {
        $staff_id = get_staff_user_id();
    }

    return total_rows(db_prefix() . 'contract_opportunities', [
        'addedfrom' => $staff_id,
        'id'        => $contract_opportunity_id,
    ]) > 0;
}

/**
 * Contract Opportunity consent URL
 * @param  mixed $id contract opportunity id
 * @return string
 */
function contract_opportunity_consent_url($id)
{
    return site_url('consent/l/' . get_contract_opportunity_hash($id));
}

/**
 * Contract Opportunity public form URL
 * @param  mixed $id contract opportunity id
 * @return string
 */
function contract_opportunities_public_url($id)
{
    return site_url('forms/l/' . get_contract_opportunity_hash($id));
}

/**
 * Get and generate contract opportunity hash if don't exists.
 * @param  mixed $id  contract opportunity id
 * @return string
 */
function get_contract_opportunity_hash($id)
{
    $CI   = &get_instance();
    $hash = '';

    $CI->db->select('hash');
    $CI->db->where('id', $id);
    $contract_opportunity = $CI->db->get(db_prefix() . 'contract_opportunities')->row();
    if ($contract_opportunity) {
        $hash = $contract_opportunity->hash;
        if (empty($hash)) {
            $hash = app_generate_hash() . '-' . app_generate_hash();
            $CI->db->where('id', $id);
            $CI->db->update(db_prefix() . 'contract_opportunities', ['hash' => $hash]);
        }
    }

    return $hash;
}

/**
 * Get contract opportunities summary
 * @return array
 */
function get_contract_opportunities_summary()
{
    $CI = &get_instance();
    if (!class_exists('contract_opportunities_model')) {
        $CI->load->model('contract_opportunities_model');
    }
    $statuses = $CI->contract_opportunities_model->get_status();

    $totalStatuses         = count($statuses);
    $has_permission_view   = staff_can('view',  'contract_opportunities');
    $sql                   = '';
    $whereNoViewPermission = '(addedfrom = ' . get_staff_user_id() . ' OR assigned=' . get_staff_user_id() . ' OR is_public = 1)';

    $statuses[] = [
        'lost'  => true,
        'name'  => _l('lost_contract_opportunities'),
        'color' => '#fc2d42',
    ];

/*    $statuses[] = [
        'junk'  => true,
        'name'  => _l('junk_contract_opportunities'),
        'color' => '',
    ];*/

    foreach ($statuses as $status) {
        $sql .= ' SELECT COUNT(*) as total';
        $sql .= ',SUM(lead_value) as value';
        $sql .= ' FROM ' . db_prefix() . 'contract_opportunities';

        if (isset($status['lost'])) {
            $sql .= ' WHERE lost=1';
        } elseif (isset($status['junk'])) {
            $sql .= ' WHERE junk=1';
        } else {
            $sql .= ' WHERE status=' . $status['id'];
        }
        if (!$has_permission_view) {
            $sql .= ' AND ' . $whereNoViewPermission;
        }
        $sql .= ' UNION ALL ';
        $sql = trim($sql);
    }

    $result = [];

    $sql    = substr($sql, 0, -10);
    $result = $CI->db->query($sql)->result();

    if (!$has_permission_view) {
        $CI->db->where($whereNoViewPermission);
    }

    $total_contract_opportunities = $CI->db->count_all_results(db_prefix() . 'contract_opportunities');

    foreach ($statuses as $key => $status) {
        if (isset($status['lost']) || isset($status['junk'])) {
            $statuses[$key]['percent'] = ($total_contract_opportunities > 0 ? number_format(($result[$key]->total * 100) / $total_contract_opportunities, 2) : 0);
        }

        $statuses[$key]['total'] = $result[$key]->total;
        $statuses[$key]['value'] = $result[$key]->value;
    }

    return $statuses;
}

/**
 * Render contract opportunity status select field with ability to create inline statuses with + sign
 * @param  array  $statuses         current statuses
 * @param  string  $selected        selected status
 * @param  string  $lang_key        the label of the select
 * @param  string  $name            the name of the select
 * @param  array   $select_attrs    additional select attributes
 * @param  boolean $exclude_default whether to exclude default Client status
 * @return string
 */
function render_contract_opportunities_status_select($statuses, $selected = '', $lang_key = '', $name = 'status', $select_attrs = [], $exclude_default = false)
{
    foreach ($statuses as $key => $status) {
        if ($status['isdefault'] == 1) {
            if ($exclude_default == false) {
                $statuses[$key]['option_attributes'] = ['data-subtext' => _l('contract_opportunities_converted_to_client')];
            } else {
                unset($statuses[$key]);
            }

            break;
        }
    }

    if (is_admin() || get_option('staff_members_create_inline_contract_opportunity_status') == '1') {
        return render_select_with_input_group($name, $statuses, ['id', 'name'], $lang_key, $selected, '<div class="input-group-btn"><a href="#" class="btn btn-default" onclick="new_contract_opportunity_status_inline();return false;" class="inline-field-new"><i class="fa fa-plus"></i></a></div>', $select_attrs);
    }

    return render_select($name, $statuses, ['id', 'name'], $lang_key, $selected, $select_attrs);
}

/**
 * Render contract opportunity source select field with ability to create inline source with + sign
 * @param  array   $sources         current sourcees
 * @param  string  $selected        selected source
 * @param  string  $lang_key        the label of the select
 * @param  string  $name            the name of the select
 * @param  array   $select_attrs    additional select attributes
 * @return string
 */
function render_contract_opportunities_source_select($sources, $selected = '', $lang_key = '', $name = 'source', $select_attrs = [])
{
    if (is_admin() || get_option('staff_members_create_inline_contract_opportunity_source') == '1') {
        echo render_select_with_input_group($name, $sources, ['id', 'name'], $lang_key, $selected, '<div class="input-group-btn"><a href="#" class="btn btn-default" onclick="new_contract_opportunity_source_inline();return false;" class="inline-field-new"><i class="fa fa-plus"></i></a></div>', $select_attrs);
    } else {
        echo render_select($name, $sources, ['id', 'name'], $lang_key, $selected, $select_attrs);
    }
}

/**
 * Load contract opportunity language
 * Used in public GDPR form
 * @param  string $contract_opportunity_id
 * @return string return loaded language
 */
function load_contract_opportunity_language($contract_opportunity_id)
{
    $CI = & get_instance();
    $CI->db->where('id', $contract_opportunity_id);
    $contract_opportunity = $CI->db->get(db_prefix() . 'contract_opportunities')->row();

    if (!$contract_opportunity || empty($contract_opportunity->default_language)) {
        return false;
    }

    $language = $contract_opportunity->default_language;

    if (!file_exists(APPPATH . 'language/' . $language)) {
        return false;
    }

    $CI->lang->is_loaded = [];
    $CI->lang->language  = [];

    $CI->lang->load($language . '_lang', $language);
    load_custom_lang_file($language);
    $CI->lang->set_last_loaded_language($language);

    return true;
}

/**
 * Check if contract opportunity email is verified
 * @param  mixed  $id contract opportunity id
 * @return boolean
 */
function is_contract_opportunity_email_verified($id)
{
    $CI = &get_instance();
    $CI->db->select('email_verified_at');
    $CI->db->where('id', $id);

    return $CI->db->get(db_prefix() . 'contract_opportunities')->row()->email_verified_at != null;
}

/**
 * Check if contract opportunity is converted to customer
 * @param  mixed  $id contract opportunity id
 * @return boolean
 */
function is_contract_opportunity_converted($id)
{
    $CI = &get_instance();
    $CI->db->where('leadid', $id);

    return $CI->db->count_all_results(db_prefix() . 'clients') > 0;
}

/**
 * Check if user can view contract opportunity
 * @param  mixed  $id contract opportunity id
 * @param  mixed  $staff_id
 * @return boolean
 */
function user_can_view_contract_opportunity($id, $staff_id = false)
{
    $CI = &get_instance();

    if (!is_staff_member() || !$id) {
        return false;
    }

    $staff_id = $staff_id ? $staff_id : get_staff_user_id();

    if (has_permission('contract_opportunities', $staff_id, 'view')) {
        return true;
    }

    $CI->db->select('id, addedfrom, assigned, status');
    $CI->db->from(db_prefix() . 'contract_opportunities');
    $CI->db->where('id', $id);
    $contract_opportunity = $CI->db->get()->row();

    if ((has_permission('contract_opportunities', $staff_id, 'view_own') && $contract_opportunity->addedfrom == $staff_id)
            || ($contract_opportunity->assigned == $staff_id && get_option('allow_staff_view_contract_opportunities_assigned') == '1')
            || ($contract_opportunity->addedfrom == $staff_id && get_option('allow_staff_view_contract_opportunities_created') == '1')
            || ($contract_opportunity->status != get_option('contract_opportunity_default_status') && get_option('contract_opportunity_lock_after_convert') == '1' && ((has_permission('contract_opportunities', $staff_id, 'view_own') && $contract_opportunity->addedfrom == $staff_id) || (has_permission('contract_opportunities', $staff_id, 'view') || $contract_opportunity->assigned == $staff_id)))
        ) {
        return true;
    }

    return false;
}
