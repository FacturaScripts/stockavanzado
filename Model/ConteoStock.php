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
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;

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
        if ($this->completed) {
            Tools::log()->warning('cannot-modify-completed-counting');
            return new LineaConteoStock();
        }

        // la referencia debe existir; si no se indica idproducto, se resuelve desde la variante
        $variante = new Variante();
        if (false === $variante->loadWhereEq('referencia', $referencia)) {
            Tools::log()->warning('reference-product-mismatch', ['%referencia%' => $referencia]);
            return new LineaConteoStock();
        }
        if (empty($idproducto)) {
            $idproducto = $variante->idproducto;
        } elseif ($variante->idproducto !== $idproducto) {
            Tools::log()->warning('reference-product-mismatch', ['%referencia%' => $referencia]);
            return new LineaConteoStock();
        }

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
            $line->cantidad += $quantity;
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

    public function addLineIfChanged(string $referencia, int $idproducto, float $quantity): LineaConteoStock
    {
        // comparamos con el stock actual del almacén; si no varía, no añadimos línea
        $stock = new Stock();
        $where = [
            Where::eq('codalmacen', $this->codalmacen),
            Where::eq('referencia', $referencia),
        ];
        $current = $stock->loadWhere($where) ? (float)$stock->cantidad : 0.0;

        if (Tools::floatCmp($current, $quantity, FS_NF0, true)) {
            return new LineaConteoStock();
        }

        return $this->addLine($referencia, $idproducto, $quantity);
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

        $newTransaction = false === self::db()->inTransaction() && self::db()->beginTransaction();
        foreach ($this->getLines(['fecha' => 'DESC']) as $line) {
            // si no está completado, saltamos
            if (false === $conteo->completed) {
                continue;
            }

            if (false === $this->pipeFalse('deleteLineCounting', $line, $conteo)) {
                if ($newTransaction) {
                    self::db()->rollback();
                }
                return false;
            }

            if (false === StockMovementManager::deleteLineCounting($line, $conteo)) {
                if ($newTransaction) {
                    self::db()->rollback();
                }
                return false;
            }

            $line->bypassCompletedCheck = true;
            if (false === $line->delete()) {
                if ($newTransaction) {
                    self::db()->rollback();
                }
                return false;
            }

            $messages = [];
            StockRebuildManager::rebuild($line->idproducto, $messages);
            if (!empty($messages)) {
                if ($newTransaction) {
                    self::db()->rollback();
                }
                return false;
            }
        }

        if (false === parent::delete()) {
            if ($newTransaction) {
                self::db()->rollback();
            }
            return false;
        }

        if ($newTransaction) {
            self::db()->commit();
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

        // el almacén debe existir
        if (empty($this->codalmacen) || false === $this->getAlmacen()->exists()) {
            Tools::log()->warning('warehouse-not-found');
            return false;
        }

        // no puede haber dos conteos abiertos (no completados) en el mismo almacén
        if (!empty($this->codalmacen) && false === (bool)$this->completed) {
            $where = [
                Where::eq('codalmacen', $this->codalmacen),
                Where::eq('completed', false),
            ];
            if (!empty($this->idconteo)) {
                $where[] = Where::notEq('idconteo', $this->idconteo);
            }
            foreach (DinConteoStock::all($where, [], 0, 1) as $open) {
                Tools::log()->warning('open-counting-already-exists', ['%idconteo%' => $open->idconteo]);
                return false;
            }
        }

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

        // si no hay líneas no se puede completar el conteo
        $lines = $conteo->getLines();
        if (empty($lines)) {
            Tools::log()->warning('counting-without-lines');
            return false;
        }

        // establecemos la fecha de fin del conteo
        $conteo->fechafin = Tools::dateTime();

        // primero recorremos las líneas para obtener el stock actual por referencia
        $stocks = [];
        foreach ($lines as $line) {
            if (false === isset($stocks[$line->referencia])) {
                $stocks[$line->referencia] = $line->cantidad;
                continue;
            }
            $stocks[$line->referencia] += $line->cantidad;
        }

        $newTransaction = false === self::db()->inTransaction() && self::db()->beginTransaction();
        foreach ($conteo->getLines(['fecha' => 'ASC']) as $line) {
            // comprobamos que el producto sigue gestionando stock
            $product = new Producto();
            if ($product->load($line->idproducto) && $product->nostock) {
                Tools::log()->warning('no-stock-this-product', ['%referencia%' => $line->referencia]);
                if ($newTransaction) {
                    self::db()->rollback();
                }
                return false;
            }

            if (false === StockMovementManager::addLineCounting($line, $conteo, $stocks[$line->referencia])) {
                if ($newTransaction) {
                    self::db()->rollback();
                }
                return false;
            }

            if (false === $this->pipeFalse('updateStock', $line, $conteo, $stocks[$line->referencia])) {
                if ($newTransaction) {
                    self::db()->rollback();
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
                self::db()->rollback();
            }
            return false;
        }

        //actualizamos el conteo
        $conteo->completed = true;
        if (false === $conteo->save()) {
            if ($newTransaction) {
                self::db()->rollback();
            }
            return false;
        }

        if ($newTransaction) {
            self::db()->commit();
        }

        return true;
    }

    public function url(string $type = 'auto', string $list = 'ListAlmacen?activetab=List'): string
    {
        return parent::url($type, $list);
    }

    protected function onChange(string $field): bool
    {
        // si el conteo ya estaba completado, no se pueden modificar fechas ni almacén
        if ($this->getOriginal('completed')
            && in_array($field, ['codalmacen', 'fechainicio', 'fechafin'], true)) {
            Tools::log()->warning('cannot-modify-completed-counting');
            return false;
        }

        // no se puede cambiar el almacén si el conteo ya tiene líneas
        if ($field === 'codalmacen' && !empty($this->idconteo) && false === empty($this->getLines())) {
            Tools::log()->warning('cannot-change-warehouse-with-lines');
            return false;
        }

        // si cambia una fecha, revalidamos las restricciones temporales
        if (in_array($field, ['fechainicio', 'fechafin'], true) && false === $this->testDate()) {
            return false;
        }

        return parent::onChange($field);
    }

    protected function saveInsert(): bool
    {
        if (false === $this->testDate()) {
            return false;
        }

        return parent::saveInsert();
    }

    protected function testDate(): bool
    {
        // no se permiten fechas futuras
        $now = time();
        if (!empty($this->fechainicio) && strtotime($this->fechainicio) > $now) {
            Tools::log()->warning('future-date-not-allowed');
            return false;
        }
        if (!empty($this->fechafin) && strtotime($this->fechafin) > $now) {
            Tools::log()->warning('future-date-not-allowed');
            return false;
        }

        // la fecha de fin no puede ser anterior a la de inicio
        if (!empty($this->fechafin) && !empty($this->fechainicio)
            && strtotime($this->fechafin) < strtotime($this->fechainicio)) {
            Tools::log()->warning('end-date-before-start-date');
            return false;
        }

        // la fecha de inicio no puede ser anterior al último conteo completado del mismo almacén
        if (!empty($this->codalmacen) && !empty($this->fechainicio)) {
            $where = [
                Where::eq('codalmacen', $this->codalmacen),
                Where::eq('completed', true),
            ];
            if (!empty($this->idconteo)) {
                $where[] = Where::notEq('idconteo', $this->idconteo);
            }
            foreach (DinConteoStock::all($where, ['fechafin' => 'DESC'], 0, 1) as $last) {
                $lastDate = $last->fechafin ?? $last->fechainicio;
                if (!empty($lastDate) && strtotime(Tools::date($this->fechainicio)) < strtotime(Tools::date($lastDate))) {
                    Tools::log()->warning('date-before-last-counting');
                    return false;
                }
            }
        }

        return true;
    }
}
