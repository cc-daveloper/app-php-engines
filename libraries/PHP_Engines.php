<?php

/**
 * PHP engines class.
 *
 * @category   apps
 * @package    php-engines
 * @subpackage libraries
 * @author     Marc Laporte
 * @copyright  2017 Marc Laporte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License version 3 or later
 * @link       https://github.com/eglooca/app-php-engines
 */

///////////////////////////////////////////////////////////////////////////////
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Lesser General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
///////////////////////////////////////////////////////////////////////////////

///////////////////////////////////////////////////////////////////////////////
// N A M E S P A C E
///////////////////////////////////////////////////////////////////////////////

namespace clearos\apps\php_engines;

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('php_engines');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

use \clearos\apps\base\Daemon as Daemon;
use \clearos\apps\base\Engine as Engine;
use \clearos\apps\base\File as File;
use \clearos\apps\base\Folder as Folder;

clearos_load_library('base/Daemon');
clearos_load_library('base/Engine');
clearos_load_library('base/File');
clearos_load_library('base/Folder');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * PHP engines class.
 *
 * @category   apps
 * @package    php-engines
 * @subpackage libraries
 * @author     Marc Laporte
 * @copyright  2017 Marc Laporte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License version 3 or later
 * @link       https://github.com/eglooca/app-php-engines
 */

class PHP_Engines extends Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $services = array();

    protected $supported = array(
        'httpd' => 'PHP 5.4',
        'rh-php56-php-fpm' => 'PHP 5.6',
        'rh-php70-php-fpm' => 'PHP 7.0'
    );

    protected $ports = array(
        'httpd' => 0,
        'rh-php56-php-fpm' => 9056,
        'rh-php70-php-fpm' => 9070,
    );

    const PATH_DAEMONS = '/var/clearos/base/daemon';
    const PATH_STATE = '/var/clearos/php_engines/state';

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Services constructor.
     */

    function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);
    }

    /**
     * Returns list of daemon services.
     *
     * @return array list of daemon services.
     * @throws Engine_Exception
     */

    public function get_services()
    {
        clearos_profile(__METHOD__, __LINE__);
        
        foreach ($this->supported as $service => $description) {
            $filename = self::PATH_DAEMONS . '/' . $service . '.php';
            $file = new File($filename);

            if ($file->exists())
                $services[] = $service;
        }

        return $services;
    }

    /**
     * Returns information on daemon services.
     *
     * @return array information on daemon services.
     * @throws Engine_Exception
     */

    public function get_services_info()
    {
        clearos_profile(__METHOD__, __LINE__);

        $services_info = array();

        foreach ($this->get_services() as $daemon_name) {
            $daemon = new Daemon($daemon_name);

            if (! $daemon->is_installed())
                continue;

            $services_info[$daemon_name]['description'] = $this->supported[$daemon_name];
            $services_info[$daemon_name]['running_state'] = $daemon->get_running_state();
            $services_info[$daemon_name]['boot_state'] = $daemon->get_boot_state();
            $services_info[$daemon_name]['multiservice'] = $daemon->is_multiservice();
            $services_info[$daemon_name]['url'] = $daemon->get_app_url();
        }

        return $services_info;
    }

    /**
     * Returns engine use from state file.
     *
     * @return array state
     * @throws Engine_Exception
     */

    public function get_deployed_state($engine = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        $folder = new Folder(self::PATH_STATE);
        $listing = $folder->get_listing();

        foreach ($listing as $config) {
            if (!preg_match('/\.conf$/', $config))
                continue;

            $file = new File(self::PATH_STATE . '/' . $config);
            $line = $file->get_contents_as_array();

            $app_state = json_decode($line[0]);
            $app_name = preg_replace('/\.conf$/', '', $config);

            foreach ($app_state->engines as $app_key => $details) {
                $item['app_name'] = $app_name;
                $item['app_description'] = $app_state->app_description;
                $item['app_key'] = $app_key;
                $state[$details][] = $item;
            }
        }

        if ($engine)
            $retval = $state[$engine];
        else
            $retval = $state;

        return $retval;
    }

    /**
     * Returns list of available engines.
     *
     * @return array list of available engines
     * @throws Engine_Exception
     */

    public function get_engines()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->supported;
    }

    /**
     * Returns list of ports for the engines.
     *
     * @return array list of ports
     * @throws Engine_Exception
     */

    public function get_ports()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->ports;
    }

    /**
     * Registers a bunch of engines at once.
     *
     * @param array $engines engine list
     * @param string $app_name app basename
     * @param string $app_description app description
     *
     * @return void
     * @throws Engine_Exception
     */

    public function register($engines, $app_name, $app_description)
    {
        clearos_profile(__METHOD__, __LINE__);

        $current['app_description'] = $app_description;
        $current['engines'] = $engines;

        $file = new File(self::PATH_STATE . '/' . $app_name . '.conf');

        if ($file->exists())
            $file->delete();

        $file->create('root', 'root', '0644');
        $file->add_lines(json_encode($current));

        // Make sure the desired engine is running
        foreach ($engines as $engine) {
            $daemon = new Daemon($engine);

            if (!$daemon->get_running_state())
                $daemon->set_running_state(TRUE);
        }
    }

    ///////////////////////////////////////////////////////////////////////////////
    // V A L I D A T I O N   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Validation routine for engines.
     *
     * @param string $engine PHP engine
     *
     * @return string error message if engine is not supported
     */

    function validate_engine($engine)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (empty($engine))
            return;

        if (!array_key_exists($engine, $this->supported))
            return lang('php_engines_invalid_engine');
    }
}
