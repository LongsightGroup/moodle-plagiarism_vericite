<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();


class backup_plagiarism_vericite_plugin extends backup_plagiarism_plugin {
    protected function define_module_plugin_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $plugin = $this->get_plugin_element();
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $vericiteconfigs = new backup_nested_element('vericite_configs');
        $vericiteconfig = new backup_nested_element('vericite_config', array('id'), array('name', 'value'));
        $pluginwrapper->add_child($vericiteconfigs);
        $vericiteconfigs->add_child($vericiteconfig);
        $vericiteconfig->set_source_table('plagiarism_vericite_config', array('cm' => backup::VAR_PARENTID));

        $vericitescores = new backup_nested_element('vericite_scores');
        $vericitescore = new backup_nested_element('vericite_score', array('id'), array('timeretrieved'));
        $pluginwrapper->add_child($vericitescores);
        $vericitescores->add_child($vericitescore);
        $vericitescore->set_source_table('plagiarism_vericite_score', array('cm' => backup::VAR_PARENTID));

        $vericitefiles = new backup_nested_element('vericite_files');
        $vericitefile = new backup_nested_element(
            'vericite_file',
            array('id'),
            array('userid', 'identifier', 'similarityscore', 'timeretrieved', 'data', 'status', 'attempts')
        );
        $pluginwrapper->add_child($vericitefiles);
        $vericitefiles->add_child($vericitefile);
        if ($userinfo) {
            $vericitefile->set_source_table('plagiarism_vericite_files', array('cm' => backup::VAR_PARENTID));
        }

        $vericitetokens = new backup_nested_element('vericite_tokens');
        $vericitetoken = new backup_nested_element(
            'vericite_token',
            array('id'),
            array('userid', 'identifier', 'timeretrieved', 'token')
        );
        $pluginwrapper->add_child($vericitetokens);
        $vericitetokens->add_child($vericitetoken);
        if ($userinfo) {
            $vericitetoken->set_source_table('plagiarism_vericite_tokens', array('cm' => backup::VAR_PARENTID));
        }
    }
}