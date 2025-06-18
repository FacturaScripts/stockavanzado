<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2013-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockRebuild;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\LineaTransferenciaStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\TransferenciaStock as DinTransferenciaStock;

/**
 * @author Cristo M. Estévez Hernández  <cristom.estevez@gmail.com>
 * @author Carlos García Gómez          <carlos@facturascripts.com>
 */
class TransferenciaStock extends Base\ModelClass
{
    use Base\ModelTrait;

    /** @var string */
    public $codalmacendestino;

    /** @var string */
    public $codalmacenorigen;

    /** @var bool */
    public $completed;

    /** @var string */
    public $fecha;

    /** @var string */
    public $fecha_completed;

    /** @var int */
    public $idtrans;

    /** @var string */
    public $nick;

    /**  @var string */
    public $observaciones;

    public function addLine(string $referencia, int $idproducto, float $quantity): LineaTransferenciaStock
    {
        $line = new LineaTransferenciaStock();
        $where = [
            new DataBaseWhere('idtrans', $this->idtrans),
            new DataBaseWhere('referencia', $referencia)
        ];
        $orderBy = ['idlinea' => 'DESC'];

        // si no existe la línea, la creamos
        if (false === $line->loadFromCode('', $where, $orderBy)) {
            $line->cantidad = $quantity;
            $line->idtrans = $this->idtrans;
            $line->idproducto = $idproducto;
            $line->referencia = $referencia;
        } else {
            // si ya existe la línea, incrementamos la cantidad
            $line->cantidad++;
        }

        $line->fecha = Tools::dateTime();
        $line->nick = Session::user()->nick;

        $resultLine = $this->pipe('addLine', $line);
        if (null !== $resultLine) {
            $line = $resultLine;
        }

        $line->save();
        return $line;
    }

    public function clear()
    {
        parent::clear();
        $this->completed = false;
        $this->fecha = Tools::dateTime();
        $this->nick = Session::user()->nick;
    }

    public function delete(): bool
    {
        $transfer = new DinTransferenciaStock();
        if (false === $transfer->loadFromCode($this->idtrans)) {
            return false;
        }

        $newTransaction = false === self::$dataBase->inTransaction() && self::$dataBase->beginTransaction();
        foreach ($this->getLines(['fecha' => 'DESC']) as $line) {
            // si la transferencia no está completada, saltamos
            if (false === $transfer->completed) {
                continue;
            }

            // ejecutamos las extensiones
            if (false === $this->pipeFalse('deleteLineTransfer', $line, $transfer)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            // eliminamos el movimiento de stock
            if (false === StockMovementManager::deleteLineTransfer($line, $transfer)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            // cargamos el stock de la línea
            $stock = new Stock();
            $where = [
                new DataBaseWhere('codalmacen', $transfer->codalmacenorigen),
                new DataBaseWhere('referencia', $line->referencia)
            ];
            if (false === $stock->loadFromCode('', $where)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            // transferimos el stock de la línea
            if (false === $stock->transferTo($transfer->codalmacendestino, 0 - $line->cantidad)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            // eliminamos la línea
            if (false === $line->delete()) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }
        }

        // eliminamos la transferencia
        if (false === parent::delete()) {
            if ($newTransaction) {
                self::$dataBase->rollback();
            }
            return false;
        }

        if ($newTransaction) {
            self::$dataBase->commit();
        }
        return true;
    }

    public function getLines(array $order = []): array
    {
        $line = new LineaTransferenciaStock();
        $where = [new DataBaseWhere('idtrans', $this->primaryColumnValue())];
        return $line->all($where, $order, 0, 0);
    }

    public function getWarehouseDest(): Almacen
    {
        $warehouse = new Almacen();
        $warehouse->loadFromCode($this->codalmacendestino);
        return $warehouse;
    }

    public function getWarehouseOrig(): Almacen
    {
        $warehouse = new Almacen();
        $warehouse->loadFromCode($this->codalmacenorigen);
        return $warehouse;
    }

    public function install(): string
    {
        // cargamos las dependencias
        new Almacen();

        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'idtrans';
    }

    public static function tableName(): string
    {
        return 'stocks_transferencias';
    }

    public function test(): bool
    {
        $this->observaciones = Tools::noHtml($this->observaciones);

        if ($this->codalmacenorigen == $this->codalmacendestino) {
            Tools::log()->warning('warehouse-cant-be-same');
            return false;
        }

        if ($this->getIdempresa($this->codalmacendestino) !== $this->getIdempresa($this->codalmacenorigen)) {
            Tools::log()->warning('warehouse-must-be-same-business');
            return false;
        }

        return parent::test();
    }

    public function transferStock(): bool
    {
        $transfer = new DinTransferenciaStock();
        if (false === $transfer->loadFromCode($this->idtrans)) {
            return false;
        }

        // si la transferencia ya está completada, no hacemos nada
        if ($transfer->completed) {
            return true;
        }

        // establecemos la fecha de fin del conteo
        $transfer->fecha_completed = Tools::dateTime();

        $newTransaction = false === static::$dataBase->inTransaction() && self::$dataBase->beginTransaction();
        foreach ($transfer->getLines(['fecha' => 'ASC']) as $line) {
            // cargamos el stock de la línea
            $stock = new Stock();
            $where = [
                new DataBaseWhere('codalmacen', $transfer->codalmacenorigen),
                new DataBaseWhere('referencia', $line->referencia)
            ];
            if (false === $stock->loadFromCode('', $where) || $stock->cantidad < $line->cantidad) {
                Tools::log()->warning('not-enough-stock', ['%reference%' => $line->referencia]);
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            // transferimos el stock de la línea
            if (false === $stock->transferTo($transfer->codalmacendestino, $line->cantidad)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            // creamos el movimiento de stock
            if (false === StockMovementManager::addLineTransferStock($line, $transfer)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            // ejecutamos las extensiones
            if (false === $this->pipeFalse('transferStock', $line, $transfer)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }
        }

        $transfer->completed = true;
        if (false === $transfer->save()) {
            if ($newTransaction) {
                self::$dataBase->rollback();
            }
            return false;
        }

        if ($newTransaction) {
            self::$dataBase->commit();
        }
        return true;
    }

    public function url(string $type = 'auto', string $list = 'ListAlmacen?activetab=List'): string
    {
        return parent::url($type, $list);
    }

    protected function getIdempresa(string $codalmacen): int
    {
        $warehouse = new Almacen;
        $warehouse->loadFromCode($codalmacen);
        return $warehouse->idempresa;
    }
}
