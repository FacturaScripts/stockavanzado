<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Plugins\StockAvanzado;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\InitClass;
use FacturaScripts\Core\Model\Role;
use FacturaScripts\Core\Model\RoleAccess;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaTransferenciaStock;
use FacturaScripts\Plugins\StockAvanzado\Model\TransferenciaStock;


/**
 * Description of Init
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Init extends InitClass
{

    public function init()
    {
        $this->loadExtension(new Extension\Controller\EditAlmacen());
        $this->loadExtension(new Extension\Controller\EditProducto());
        $this->loadExtension(new Extension\Controller\ListAlmacen());
        $this->loadExtension(new Extension\Controller\ListProducto());
        $this->loadExtension(new Extension\Model\Base\BusinessDocumentLine());
    }

    public function update()
    {
        $this->migrateData();
        $this->createRoleForPlugin();
    }

    private function createRoleForPlugin()
    {
        $createSomething = false;
        $dataBase = new DataBase();
        $dataBase->beginTransaction();
        
        $role = new Role();
        $nameOfRole = 'StockAvanzado'; // Name of plugin in facturascripts.ini
        
        // Check if exist the name of this plugin between roles
        if (false === $role->loadFromCode($nameOfRole)) 
        {
            // NO exist, then will be create
            $role->codrole = $nameOfRole;
            $role->descripcion = 'Rol - plugin ' . $nameOfRole;
            
            // Try to save. If can't do it will be to do rollback for the 
            // Transaction and not will continue
            if (false === $role->save())
            {   // Can't create it
                $dataBase->rollback();
            }
            
            $createSomething = true;
        }
        
            // if the plugin is active and then we decide it will be deactive, 
            // the permissions of the rule will be delete.
            // Then always is necesary to check ir they exist
            $nameControllers = ['EditConteoStock', 'EditTransferenciaStock', 'ReportStock'];
            foreach ($nameControllers as $nameController) 
            {
                $roleAccess = new RoleAccess();
                
                // Check if exist the $nameController between permissions for 
                // this role/plugin
                $where = [
                    new DataBaseWhere('codrole', $nameOfRole),
                    new DataBaseWhere('pagename', $nameController)
                ];
                
                if (false === $roleAccess->loadFromCode('', $where)) 
                {
                    // NO exist, then will be create
                    $roleAccess->allowdelete = true;
                    $roleAccess->allowupdate = true;
                    $roleAccess->codrole = $nameOfRole; 
                    $roleAccess->pagename = $nameController;
                    $roleAccess->onlyownerdata = false;

                    // Try to save. If can't do it will be to do rollback for the 
                    // Transaction and not will continue
                    if (false === $roleAccess->save())
                    {   // Can't create it
                        $dataBase->rollback();
                        return; // to not create permission for this role
                    }

                    $createSomething = true;
                }
            }
            
        // Was create something?
        if ($createSomething === true)
        {
            $dataBase->commit();
        } else 
        {
            $dataBase->rollback();
        }
        
        return;
    }

    private function migrateData()
    {
        $database = new DataBase();
        if (false === $database->tableExists('transferenciasstock')) {
            return;
        }

        foreach ($database->select('SELECT * FROM transferenciasstock') as $row) {
            $trans = new TransferenciaStock($row);
            if (false === $trans->save()) {
                return;
            }
        }

        if (false === $database->tableExists('lineastransferenciasstock')) {
            $database->exec('DROP TABLE transferenciasstock;');
            return;
        }

        LineaTransferenciaStock::setDisableUpdateStock(true);
        foreach ($database->select('SELECT * FROM lineastransferenciasstock') as $row) {
            $line = new LineaTransferenciaStock($row);
            if (false === $line->save()) {
                return;
            }
        }

        $database->exec('DROP TABLE lineastransferenciasstock;');
        $database->exec('DROP TABLE transferenciasstock;');
    }
}
