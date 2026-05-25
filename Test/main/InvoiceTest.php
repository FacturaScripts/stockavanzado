<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2024-2026 Carlos García Gómez <carlos@facturascripts.com>
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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;
    use DefaultSettingsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testCreateFacturaCliente(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto y le ponemos stock
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos una factura directa (sin albarán previo)
        $factura = new FacturaCliente();
        $this->assertTrue($factura->setSubject($customer));
        $this->assertTrue($factura->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($factura->save());

        // añadimos una línea
        $linea = $factura->getNewProductLine($product->referencia);
        $linea->cantidad = 4;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que el stock se ha reducido
        $this->assertTrue($stock->reload());
        $this->assertEquals(6, $stock->cantidad);
        $this->assertEquals(6, $stock->disponible);

        // comprobamos que se ha generado el movimiento de stock
        $movement = new MovimientoStock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals(-4, $movement->cantidad);
        $this->assertEquals(-4, $movement->saldo);
        $this->assertEquals($factura->id(), $movement->docid);
        $this->assertEquals($factura->modelClassName(), $movement->docmodel);

        // eliminamos
        $this->assertTrue($factura->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testFacturaClienteMovementUpdatesWhenDateChanges(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto con stock
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos una factura directa
        $factura = new FacturaCliente();
        $this->assertTrue($factura->setSubject($customer));
        $this->assertTrue($factura->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($factura->save());

        // añadimos una línea
        $linea = $factura->getNewProductLine($product->referencia);
        $linea->cantidad = 3;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que el movimiento se ha creado con la fecha de la factura
        $movement = new MovimientoStock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals($factura->fecha, $movement->fecha);

        // cambiamos la fecha de la factura a un día antes
        $newDate = date('d-m-Y', strtotime($factura->fecha . ' -1 day'));
        $factura->fecha = $newDate;
        $this->assertTrue($factura->save());

        // comprobamos que el movimiento refleja la nueva fecha
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals($newDate, $movement->fecha);
        $this->assertEquals($factura->id(), $movement->docid);
        $this->assertEquals(-3, $movement->cantidad);

        // eliminamos
        $this->assertTrue($factura->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCreateFacturaProveedor(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto (sin stock)
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos una factura de proveedor directa (sin albarán previo)
        $factura = new FacturaProveedor();
        $this->assertTrue($factura->setSubject($proveedor));
        $this->assertTrue($factura->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($factura->save());

        // añadimos una línea
        $linea = $factura->getNewProductLine($product->referencia);
        $linea->cantidad = 7;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que el stock ha aumentado
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(7, $stock->cantidad);
        $this->assertEquals(7, $stock->disponible);

        // comprobamos que se ha generado el movimiento de stock
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals(7, $movement->cantidad);
        $this->assertEquals(7, $movement->saldo);
        $this->assertEquals($factura->id(), $movement->docid);
        $this->assertEquals($factura->modelClassName(), $movement->docmodel);

        // eliminamos
        $this->assertTrue($factura->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testMovementDatesFollowDocumentDates(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto con stock
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán con fecha y hora del pasado
        $pastDate = date('d-m-Y', strtotime('-3 days'));
        $pastHour = '09:15:00';

        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->setDate($pastDate, $pastHour));
        $this->assertTrue($albaran->save());

        // añadimos una línea
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // el movimiento debe tener la fecha y hora del albarán
        $movement = new MovimientoStock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals($pastDate, $movement->fecha);
        $this->assertEquals($pastHour, $movement->hora);
        $this->assertEquals($albaran->id(), $movement->docid);

        // ahora aprobamos el albarán a factura (la factura toma fecha/hora actuales)
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaCliente' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId);

        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());

        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);
        $factura = $facturas[0];

        // el movimiento del albarán mantiene su fecha/hora originales
        $albaranMovement = new MovimientoStock();
        $this->assertTrue($albaranMovement->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia),
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName()),
        ]));
        $this->assertEquals($pastDate, $albaranMovement->fecha);
        $this->assertEquals($pastHour, $albaranMovement->hora);

        // el movimiento de la factura toma su propia fecha/hora (distintas del albarán)
        $facturaMovement = new MovimientoStock();
        $this->assertTrue($facturaMovement->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia),
            Where::eq('docid', $factura->id()),
            Where::eq('docmodel', $factura->modelClassName()),
        ]));
        $this->assertEquals($factura->fecha, $facturaMovement->fecha);
        $this->assertEquals($factura->hora, $facturaMovement->hora);
        $this->assertNotEquals($albaranMovement->fecha, $facturaMovement->fecha);

        // eliminamos
        $this->assertTrue($factura->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
