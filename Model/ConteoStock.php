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

namespace FacturaScripts\Plugins\StockAvanzado\Model;

use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockRebuildManager;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\ConteoStock as DinConteoStock;
use FacturaScripts\Dinamic\Model\LineaConteoStock;

/**
 * Description of ConteoStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ConteoStock extends ModelClass
{
    use ModelTrait;

    /** @var string */
    public $codalmacen;

    /** @var bool */
    public $completed;

    /** @var string */
    public $fechafin;

    /** @var string */
    public $fechainicio;

    /** @var int */
    public $idconteo;

    /** @var string */
    public $nick;

    /** @var string */
    public $observaciones;

    public function addLine(string $referencia, int $idproducto, float $quantity): LineaConteoStock
    {
        $line = new LineaConteoStock();
        $where = [
            Where::eq('idconteo', $this->idconteo),
            Where::eq('referencia', $referencia)
        ];
        $orderBy = ['idlinea' => 'DESC'];

        // si no existe la línea, la creamos
        if (false === $line->loadWhere($where, $orderBy)) {
            $line->cantidad = $quantity;
            $line->idconteo = $this->idconteo;
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

    public function clear(): void
    {
        parent::clear();
        $this->completed = false;
        $this->fechainicio = Tools::date();
        $this->nick = Session::user()->nick;
    }

    public function delete(): bool
    {
        $conteo = new DinConteoStock();
        if (false === $conteo->load($this->idconteo)) {
            return false;
        }

        $newTransaction = false === static::$dataBase->inTransaction() && self::$dataBase->beginTransaction();
        foreach ($this->getLines(['fecha' => 'DESC']) as $line) {
            // si no está completado, saltamos
            if (false === $conteo->completed) {
                continue;
            }

            if (false === $this->pipeFalse('deleteLineCounting', $line, $conteo)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            if (false === StockMovementManager::deleteLineCounting($line, $conteo)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            if (false === $line->delete()) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            $messages = [];
            StockRebuildManager::rebuild($line->idproducto, $messages);
            if (!empty($messages)) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }
        }

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

    public function getAlmacen(): Almacen
    {
        return Almacenes::get($this->codalmacen);
    }

    public function getLines(array $order = []): array
    {
        $where = [Where::eq('idconteo', $this->idconteo)];
        return LineaConteoStock::all($where, $order);
    }

    public function install(): string
    {
        // cargamos las dependencias
        new Almacen();

        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'idconteo';
    }

    public static function tableName(): string
    {
        return 'stocks_conteos';
    }

    public function test(): bool
    {
        $this->observaciones = Tools::noHtml($this->observaciones);
        return parent::test();
    }

    public function updateStock(): bool
    {
        $conteo = new DinConteoStock();
        if (false === $conteo->load($this->idconteo)) {
            return false;
        }

        // si el conteo ya está completado, no hacemos nada
        if ($conteo->completed) {
            return true;
        }

        // establecemos la fecha de fin del conteo
        $conteo->fechafin = Tools::dateTime();

        // primero recorremos las líneas para obtener el stock actual por referencia
        $stocks = [];
        foreach ($conteo->getLines() as $line) {
            if (false === isset($stocks[$line->referencia])) {
                $stocks[$line->referencia] = $line->cantidad;
                continue;
            }
            $stocks[$line->referencia] += $line->cantidad;
        }

        $newTransaction = false === static::$dataBase->inTransaction() && self::$dataBase->beginTransaction();
        foreach ($conteo->getLines(['fecha' => 'ASC']) as $line) {
            if (false === StockMovementManager::addLineCounting($line, $conteo, $stocks[$line->referencia])) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            if (false === $this->pipeFalse('updateStock', $line, $conteo, $stocks[$line->referencia])) {
                if ($newTransaction) {
                    self::$dataBase->rollback();
                }
                return false;
            }

            $messages = [];
            StockRebuildManager::rebuild($line->idproducto, $messages);
            if (empty($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                Tools::log()->warning($message);
            }

            if ($newTransaction) {
                self::$dataBase->rollback();
            }
            return false;
        }

        //actualizamos el conteo
        $conteo->completed = true;
        if (false === $conteo->save()) {
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
}
