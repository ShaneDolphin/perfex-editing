<?php

namespace app\services\leads;

use app\services\AbstractKanban;

class LeadsKanban extends AbstractKanban
{
    protected function table(): string
    {
        return 'contract_opportunities';
    }

    public function defaultSortDirection()
    {
        return get_option('default_leads_kanban_sort_type');
    }

    public function defaultSortColumn()
    {
        return get_option('default_leads_kanban_sort');
    }

    public function limit()
    {
        return get_option('leads_kanban_limit');
    }

    protected function applySearchQuery($q): self
    {
        if (!startsWith($q, '#')) {
            $q = $this->ci->db->escape_like_str($this->q);
            $this->ci->db->where('(' . db_prefix() . 'leads.name LIKE "%' . $q . '%" ESCAPE \'!\' OR ' . db_prefix() . 'leads_sources.name LIKE "%' . $q . '%" ESCAPE \'!\' OR ' . db_prefix() . 'leads.email LIKE "%' . $q . '%" ESCAPE \'!\' OR ' . db_prefix() . 'leads.phonenumber LIKE "%' . $q . '%" ESCAPE \'!\' OR ' . db_prefix() . 'leads.company LIKE "%' . $q . '%" ESCAPE \'!\' OR CONCAT(' . db_prefix() . 'staff.firstname, \' \', ' . db_prefix() . 'staff.lastname) LIKE "%' . $q . '%" ESCAPE \'!\')');
        } else {
            $this->ci->db->where(db_prefix() . 'contract_opportunities.id IN
                (SELECT rel_id FROM ' . db_prefix() . 'taggables WHERE tag_id IN
                (SELECT id FROM ' . db_prefix() . 'tags WHERE name="' . $this->ci->db->escape_str(strafter($q, '#')) . '")
                AND ' . db_prefix() . 'taggables.rel_type=\'lead\' GROUP BY rel_id HAVING COUNT(tag_id) = 1)
                ');
        }

        return $this;
    }

    protected function initiateQuery(): self
    {
        $this->ci->db->select(db_prefix() . 'contract_opportunities.title, ' . db_prefix() . 'contract_opportunities.website, ' . db_prefix() . 'contract_opportunities.lead_value, ' . db_prefix() . 'contract_opportunities.address, ' . db_prefix() . 'contract_opportunities.city, ' . db_prefix() . 'contract_opportunities.state, ' . db_prefix() . 'contract_opportunities.country, ' . db_prefix() . 'contract_opportunities.zip, ' . db_prefix() . 'contract_opportunities.name as lead_name,' . db_prefix() . 'contract_opportunities_sources.name as source_name,' . db_prefix() . 'contract_opportunities.id as id,' . db_prefix() . 'contract_opportunities.assigned,' . db_prefix() . 'contract_opportunities.email,' . db_prefix() . 'contract_opportunities.phonenumber,' . db_prefix() . 'contract_opportunities.company,' . db_prefix() . 'contract_opportunities.dateadded,' . db_prefix() . 'contract_opportunities.status,' . db_prefix() . 'contract_opportunities.lastcontact,(SELECT COUNT(*) FROM ' . db_prefix() . 'clients WHERE leadid=' . db_prefix() . 'contract_opportunities.id) as is_lead_client, (SELECT COUNT(id) FROM ' . db_prefix() . 'files WHERE rel_id=' . db_prefix() . 'contract_opportunities.id AND rel_type="lead") as total_files, (SELECT COUNT(id) FROM ' . db_prefix() . 'notes WHERE rel_id=' . db_prefix() . 'contract_opportunities.id AND rel_type="lead") as total_notes,(SELECT GROUP_CONCAT(name SEPARATOR ",") FROM ' . db_prefix() . 'taggables JOIN ' . db_prefix() . 'tags ON ' . db_prefix() . 'taggables.tag_id = ' . db_prefix() . 'tags.id WHERE rel_id = ' . db_prefix() . 'contract_opportunities.id and rel_type="lead" ORDER by tag_order ASC) as tags');
        $this->ci->db->from('contract_opportunities');
        $this->ci->db->join(db_prefix() . 'contract_opportunities_sources', db_prefix() . 'contract_opportunities_sources.id=' . db_prefix() . 'contract_opportunities.source');
        $this->ci->db->join(db_prefix() . 'staff', db_prefix() . 'staff.staffid=' . db_prefix() . 'contract_opportunities.assigned', 'left');
        $this->ci->db->where('status', $this->status);

        if (staff_cant('view', 'contract_opportunities')) {
            $this->ci->db->where('(assigned = ' . get_staff_user_id() . ' OR addedfrom=' . get_staff_user_id() . ' OR is_public=1)');
        }

        return $this;
    }
}
