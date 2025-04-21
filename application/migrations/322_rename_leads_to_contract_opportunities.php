<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Rename_leads_to_contract_opportunities extends CI_Migration
{
    public function up()
    {
        $this->dbforge->rename_table(db_prefix() . 'leads', db_prefix() . 'contract_opportunities');
        $this->dbforge->rename_table(db_prefix() . 'leads_status', db_prefix() . 'contract_opportunities_status');
        $this->dbforge->rename_table(db_prefix() . 'leads_sources', db_prefix() . 'contract_opportunities_sources');
        $this->dbforge->rename_table(db_prefix() . 'lead_activity_log', db_prefix() . 'contract_opportunity_activity_log');
        $this->dbforge->rename_table(db_prefix() . 'lead_integration_emails', db_prefix() . 'contract_opportunity_integration_emails');
        
        $tables = $this->db->list_tables();
        foreach ($tables as $table) {
            if (in_array($table, [
                db_prefix() . 'contract_opportunities',
                db_prefix() . 'contract_opportunities_status',
                db_prefix() . 'contract_opportunities_sources',
                db_prefix() . 'contract_opportunity_activity_log',
                db_prefix() . 'contract_opportunity_integration_emails'
            ])) {
                continue;
            }
            
            $fields = $this->db->list_fields($table);
            foreach ($fields as $field) {
                if ($field == 'leadid') {
                    $this->db->query('ALTER TABLE ' . $table . ' CHANGE leadid contract_opportunity_id INT(11)');
                }
                if ($field == 'lead_id') {
                    $this->db->query('ALTER TABLE ' . $table . ' CHANGE lead_id contract_opportunity_id INT(11)');
                }
                if ($field == 'lead_status') {
                    $this->db->query('ALTER TABLE ' . $table . ' CHANGE lead_status contract_opportunity_status INT(11)');
                }
                if ($field == 'lead_source') {
                    $this->db->query('ALTER TABLE ' . $table . ' CHANGE lead_source contract_opportunity_source INT(11)');
                }
            }
        }
    }
    
    public function down()
    {
        $this->dbforge->rename_table(db_prefix() . 'contract_opportunities', db_prefix() . 'leads');
        $this->dbforge->rename_table(db_prefix() . 'contract_opportunities_status', db_prefix() . 'leads_status');
        $this->dbforge->rename_table(db_prefix() . 'contract_opportunities_sources', db_prefix() . 'leads_sources');
        $this->dbforge->rename_table(db_prefix() . 'contract_opportunity_activity_log', db_prefix() . 'lead_activity_log');
        $this->dbforge->rename_table(db_prefix() . 'contract_opportunity_integration_emails', db_prefix() . 'lead_integration_emails');
        
        $tables = $this->db->list_tables();
        foreach ($tables as $table) {
            $fields = $this->db->list_fields($table);
            foreach ($fields as $field) {
                if ($field == 'contract_opportunity_id') {
                    $this->db->query('ALTER TABLE ' . $table . ' CHANGE contract_opportunity_id leadid INT(11)');
                }
                if ($field == 'contract_opportunity_status') {
                    $this->db->query('ALTER TABLE ' . $table . ' CHANGE contract_opportunity_status lead_status INT(11)');
                }
                if ($field == 'contract_opportunity_source') {
                    $this->db->query('ALTER TABLE ' . $table . ' CHANGE contract_opportunity_source lead_source INT(11)');
                }
            }
        }
    }
}
