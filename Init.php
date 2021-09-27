<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2021 Carlos Garcia Gomez <carlos@facturascripts.com>
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
    const ROLE_NAME = 'StockAvanzado';

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
        $this->createRoleForPlugin();
        $this->migrateData();
    }

    private function createRoleForPlugin()
    {
        $dataBase = new DataBase();
        $dataBase->beginTransaction();

        // creates the role if not exists
        $role = new Role();
        if (false === $role->loadFromCode(self::ROLE_NAME)) {
            $role->codrole = $role->descripcion = self::ROLE_NAME;
            if (false === $role->save()) {
                /// exit and rollback on fail
                $dataBase->rollback();
                return;
            }
        }

        // check the role permissions
        $controllerNames = ['EditConteoStock', 'EditTransferenciaStock', 'ReportStock'];
        foreach ($controllerNames as $controllerName) {
            $roleAccess = new RoleAccess();
            $where = [
                new DataBaseWhere('codrole', self::ROLE_NAME),
                new DataBaseWhere('pagename', $controllerName)
            ];
            if ($roleAccess->loadFromCode('', $where)) {
                continue;
            }

            // creates the permission if not exists
            $roleAccess->allowdelete = true;
            $roleAccess->allowupdate = true;
            $roleAccess->codrole = self::ROLE_NAME;
            $roleAccess->pagename = $controllerName;
            $roleAccess->onlyownerdata = false;
            if (false === $roleAccess->save()) {
                /// exit and rollback on fail
                $dataBase->rollback();
                return;
            }
        }

        // commit if there is no problem
        $dataBase->commit();
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
