<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Controller\ApiRoot;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Model\Role;
use FacturaScripts\Core\Model\RoleAccess;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\StockMinMaxManager;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Dinamic\Model\LineaTransferenciaStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\TransferenciaStock;

/**
 * Description of Init
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Init extends InitClass
{
    const ROLE_NAME = 'StockAvanzado';

    /** @var DataBase */
    private $db;

    public function init(): void
    {
        // extensiones
        $this->loadExtension(new Extension\Controller\EditAlmacen());
        $this->loadExtension(new Extension\Controller\EditProducto());
        $this->loadExtension(new Extension\Controller\ListAlmacen());
        $this->loadExtension(new Extension\Controller\ListProducto());
        $this->loadExtension(new Extension\Controller\ReportProducto());
        $this->loadExtension(new Extension\Model\Stock());
        $this->loadExtension(new Extension\Model\Base\BusinessDocumentLine());

        // API
        Kernel::addRoute('/api/3/counting-execute', 'ApiCountingExecute', -1);
        Kernel::addRoute('/api/3/transfer-execute', 'ApiTransferExecute', -1);
        ApiRoot::addCustomResource('counting-execute');
        ApiRoot::addCustomResource('transfer-execute');

        // workers
        WorkQueue::addWorker('RebuildStockMovements', 'Model.Producto.rebuildStockMovements');
        WorkQueue::addWorker('UpdateStockMovements', 'Model.Producto.updateStockMovements');
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        $this->unlinkUsers();

        new MovimientoStock();

        $this->createRoleForPlugin();
        $this->updateEmailNotifications();
        $this->migrateData();
    }

    private function createRoleForPlugin(): void
    {
        // creates the role if not exists
        $role = new Role();
        if (false === $role->load(self::ROLE_NAME)) {
            $role->codrole = $role->descripcion = self::ROLE_NAME;
            if (false === $role->save()) {
                return;
            }
        }

        $this->db()->beginTransaction();

        // check the role permissions
        $controllerNames = ['EditConteoStock', 'EditTransferenciaStock', 'ReportStock'];
        foreach ($controllerNames as $controllerName) {
            $roleAccess = new RoleAccess();
            $where = [
                Where::eq('codrole', self::ROLE_NAME),
                Where::eq('pagename', $controllerName)
            ];
            if ($roleAccess->loadWhere($where)) {
                continue;
            }

            // creates the permission if not exists
            $roleAccess->allowdelete = true;
            $roleAccess->allowupdate = true;
            $roleAccess->codrole = self::ROLE_NAME;
            $roleAccess->pagename = $controllerName;
            $roleAccess->onlyownerdata = false;
            if (false === $roleAccess->save()) {
                // exit and rollback on fail
                $this->db()->rollback();
                return;
            }
        }

        // commit if there is no problem
        $this->db()->commit();
    }

    protected function db(): DataBase
    {
        if ($this->db === null) {
            $this->db = new DataBase();
            $this->db->connect();
        }

        return $this->db;
    }

    /**
     * Migración de datos de la versión 2017
     * @return void
     */
    private function migrateData(): void
    {
        if (false === $this->db()->tableExists('transferenciasstock')) {
            return;
        }

        foreach ($this->db()->select('SELECT * FROM transferenciasstock') as $row) {
            $trans = new TransferenciaStock($row);
            $trans->completed = true;
            if (false === $trans->save()) {
                return;
            }
        }

        if (false === $this->db()->tableExists('lineastransferenciasstock')) {
            $this->db()->exec('DROP TABLE transferenciasstock;');
            return;
        }

        foreach ($this->db()->select('SELECT * FROM lineastransferenciasstock') as $row) {
            $line = new LineaTransferenciaStock($row);
            if (false === $line->save()) {
                return;
            }
        }

        $this->db()->exec('DROP TABLE lineastransferenciasstock;');
        $this->db()->exec('DROP TABLE transferenciasstock;');
    }

    private function unlinkUsers(): void
    {
        $tables = ['stocks_conteos', 'stocks_lineasconteos', 'stocks_transferencias'];
        foreach ($tables as $table) {
            if (false === $this->db()->tableExists($table)) {
                continue;
            }

            $sqlUnlink = 'update ' . $table . ' set nick = null'
                . ' where nick is not null and nick not in (select nick from users)';

            $this->db()->exec($sqlUnlink);
        }
    }

    private function updateEmailNotifications(): void
    {
        $notificationMax = new EmailNotification();
        if (!$notificationMax->load(StockMinMaxManager::NOTIFICATION_STOCK_MAX)) {
            $notificationMax->name = StockMinMaxManager::NOTIFICATION_STOCK_MAX;
            $notificationMax->body = "Hola {nick}.\n\nSe ha alcanzado el stock máximo de los siguientes productos.";
            $notificationMax->subject = 'Stock máximo alcanzado';
            $notificationMax->enabled = true;
            $notificationMax->save();
        }

        $notificationMin = new EmailNotification();
        if (!$notificationMin->load(StockMinMaxManager::NOTIFICATION_STOCK_MIN)) {
            $notificationMin->name = StockMinMaxManager::NOTIFICATION_STOCK_MIN;
            $notificationMin->body = "Hola {nick}.\n\nSe ha alcanzado el stock mínimo de los siguientes productos.";
            $notificationMin->subject = 'Stock mínimo alcanzado';
            $notificationMin->enabled = true;
            $notificationMin->save();
        }
    }
}
