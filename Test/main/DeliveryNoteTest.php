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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Lib\Calculator;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\LogMessage;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class DeliveryNoteTest extends TestCase
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

    public function testCreateAlbaranCliente(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que se ha actualizado el stock del producto
        $this->assertTrue($stock->reload());
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);
        $this->assertEquals(0, $stock->pterecibir);
        $this->assertEquals(0, $stock->reservada);

        // comprobamos que hay un movimiento de stock
        $movement = new MovimientoStock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals(-10, $movement->cantidad);
        $this->assertEquals(-10, $movement->saldo);
        $this->assertEquals($albaran->id(), $movement->docid);
        $this->assertEquals($albaran->modelClassName(), $movement->docmodel);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranClienteMovementUpdatesWhenDateChanges(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 5;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que el movimiento se ha creado con la fecha del albarán
        $movement = new MovimientoStock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals($albaran->fecha, $movement->fecha);

        // cambiamos la fecha del albarán a un día antes
        $newDate = date('d-m-Y', strtotime($albaran->fecha . ' -1 day'));
        $albaran->fecha = $newDate;
        $this->assertTrue($albaran->save());

        // comprobamos que el movimiento refleja la nueva fecha
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals($newDate, $movement->fecha);
        $this->assertEquals($albaran->id(), $movement->docid);
        $this->assertEquals(-5, $movement->cantidad);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranClienteNostockProductDoesNotGenerateMovement(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto que no gestiona stock
        $product = $this->getRandomProduct();
        $product->nostock = true;
        $this->assertTrue($product->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // no debe existir registro de stock para este producto
        $stock = new Stock();
        $this->assertFalse($stock->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));

        // no debe existir movimiento de stock para este albarán
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia),
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]));

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranClienteReturnedStatusRemovesMovement(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que se ha actualizado el stock y hay movimiento
        $this->assertTrue($stock->reload());
        $this->assertEquals(0, $stock->cantidad);

        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals(-10, $movement->cantidad);

        // buscamos un estado devuelto (editable = false, actualizastock = 0, generadoc = null)
        $returnedStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if (false === (bool)$status->editable
                && 0 === (int)$status->actualizastock
                && empty($status->generadoc)) {
                $returnedStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($returnedStatusId, 'no-returned-status-found');

        // cambiamos el albarán al estado devuelto
        $albaran->idestado = $returnedStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // el stock vuelve al valor original
        $this->assertTrue($stock->reload());
        $this->assertEquals(10, $stock->cantidad);
        $this->assertEquals(10, $stock->disponible);

        // no debe quedar movimiento de stock para este albarán
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere(array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ])));

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranClienteReturnedThenInvoicedAndInvoiceDeleted(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // buscamos el estado devuelto y el estado que genera factura
        $returnedStatusId = null;
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if (false === (bool)$status->editable
                && 0 === (int)$status->actualizastock
                && empty($status->generadoc)) {
                $returnedStatusId = $status->idestado;
            }
            if ('FacturaCliente' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
            }
        }
        $this->assertNotNull($returnedStatusId, 'no-returned-status-found');
        $this->assertNotNull($invoiceStatusId, 'no-invoice-status-found');

        // pasamos el albarán a devuelto
        $albaran->idestado = $returnedStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // el stock vuelve a 10 y no hay movimiento
        $this->assertTrue($stock->reload());
        $this->assertEquals(10, $stock->cantidad);

        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $whereAlbaran = array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]);
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereAlbaran));

        // ahora pasamos el albarán al estado que genera factura
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // se ha creado la factura
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);

        // hay 2 movimientos: el del albarán (-10) y el de la factura (delta 0)
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(2, $movimientos);

        // borramos la factura
        $this->assertTrue($facturas[0]->delete());
        $this->runWorkQueue();

        // el albarán debe volver al estado devuelto
        $this->assertTrue($albaran->reload());
        $this->assertEquals($returnedStatusId, $albaran->idestado);

        // por tanto NO debe quedar movimiento de stock para el albarán
        $movement = new MovimientoStock();
        $this->assertFalse(
            $movement->loadWhere($whereAlbaran),
            'el albarán en estado devuelto no debe tener movimiento de stock'
        );

        // y el stock debe seguir en 10
        $this->assertTrue($stock->reload());
        $this->assertEquals(10, $stock->cantidad);
        $this->assertEquals(10, $stock->disponible);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranClienteInvoicedAndInvoiceDeleted(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());
        $originalStatusId = $albaran->idestado;

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // el stock baja a 0 y hay movimiento de -10 para el albarán
        $this->assertTrue($stock->reload());
        $this->assertEquals(0, $stock->cantidad);

        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $whereAlbaran = array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]);
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereAlbaran));
        $this->assertEquals(-10, $movement->cantidad);

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaCliente' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId, 'no-invoice-status-found');

        // pasamos el albarán al estado que genera factura
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // se ha creado la factura
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);

        // hay 2 movimientos: el del albarán (-10) y el de la factura (delta 0)
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(2, $movimientos);

        // borramos la factura
        $this->assertTrue($facturas[0]->delete());
        $this->runWorkQueue();

        // el albarán vuelve al estado original (editable, actualizastock = -1)
        $this->assertTrue($albaran->reload());
        $this->assertEquals($originalStatusId, $albaran->idestado);

        // el movimiento del albarán sigue existiendo con -10
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereAlbaran));
        $this->assertEquals(-10, $movement->cantidad);

        // y el stock sigue en 0
        $this->assertTrue($stock->reload());
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranProveedorGroupedIntoInvoice(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $supplier = $this->getRandomSupplier();
        $this->assertTrue($supplier->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($supplier));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 5;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // hay un movimiento de +5 para el albarán
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $whereAlbaran = array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]);
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereAlbaran));
        $this->assertEquals(5, $movement->cantidad);

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId, 'no-invoice-status-found');

        // agrupamos el albarán en una factura, reproduciendo el flujo de DocumentStitcher:
        // captura las líneas antes de cambiar el estado, de modo que las líneas
        // que recibe el generador pueden estar desactualizadas
        $staleLines = $albaran->getLines();
        $this->assertCount(1, $staleLines);
        $quantities = [$staleLines[0]->id() => 5.0];

        $albaran->setDocumentGeneration(false);
        $albaran->idestado = $invoiceStatusId;
        $saved = $albaran->save();

        // restauramos la generación de documentos, porque es un flag estático
        // y afecta a los demás tests
        $albaran->setDocumentGeneration(true);
        $this->assertTrue($saved);

        $generator = new BusinessDocumentGenerator();
        $this->assertTrue($generator->generate($albaran, 'FacturaProveedor', $staleLines, $quantities));
        $this->runWorkQueue();

        // se ha creado la factura
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);

        // hay 2 movimientos: el del albarán (+5) y el de la factura (delta 0)
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(2, $movimientos);

        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereAlbaran));
        $this->assertEquals(5, $movement->cantidad, 'el movimiento del albarán debe ser 5');

        $whereFactura = array_merge($whereRef, [
            Where::eq('docid', $facturas[0]->id()),
            Where::eq('docmodel', $facturas[0]->modelClassName())
        ]);
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereFactura));
        $this->assertEquals(0, $movement->cantidad, 'el movimiento de la factura debe ser 0');

        // el stock es 5
        $stock = new Stock();
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(5, $stock->cantidad);

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($supplier->delete());
        $this->assertTrue($supplier->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranClienteReturnedWithMultipleLinesSameReference(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos dos líneas con la misma referencia (4 + 6 = 10 unidades)
        $linea1 = $albaran->getNewProductLine($product->referencia);
        $linea1->cantidad = 4;
        $linea1->pvpunitario = 10;
        $this->assertTrue($linea1->save());

        $linea2 = $albaran->getNewProductLine($product->referencia);
        $linea2->cantidad = 6;
        $linea2->pvpunitario = 10;
        $this->assertTrue($linea2->save());

        // un único movimiento consolidado de -10
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(1, $movimientos);
        $this->assertEquals(-10, $movimientos[0]->cantidad);

        // buscamos el estado devuelto
        $returnedStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if (false === (bool)$status->editable
                && 0 === (int)$status->actualizastock
                && empty($status->generadoc)) {
                $returnedStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($returnedStatusId, 'no-returned-status-found');

        // cambiamos el albarán al estado devuelto
        $albaran->idestado = $returnedStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // el stock vuelve a 10
        $this->assertTrue($stock->reload());
        $this->assertEquals(10, $stock->cantidad);
        $this->assertEquals(10, $stock->disponible);

        // no debe quedar el movimiento consolidado
        $this->assertCount(0, MovimientoStock::all($whereRef));

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranClienteConsolidatesSameReferenceLines(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos dos líneas con la misma referencia (4 + 6 = 10 unidades)
        $linea1 = $albaran->getNewProductLine($product->referencia);
        $linea1->cantidad = 4;
        $linea1->pvpunitario = 10;
        $this->assertTrue($linea1->save());

        $linea2 = $albaran->getNewProductLine($product->referencia);
        $linea2->cantidad = 6;
        $linea2->pvpunitario = 10;
        $this->assertTrue($linea2->save());

        // el stock debe reflejar las dos líneas consolidadas
        $this->assertTrue($stock->reload());
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);

        // debe existir un único movimiento de stock con la cantidad consolidada
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia),
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ];
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(1, $movimientos, 'expected a single consolidated stock movement');
        $this->assertEquals(-10, $movimientos[0]->cantidad);
        $this->assertEquals(-10, $movimientos[0]->saldo);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testPartialAlbaranCliente(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // actualizamos los totales
        $lines = $albaran->getLines();
        $this->assertTrue(Calculator::calculate($albaran, $lines, true));

        // facturamos parcialmente el albarán
        $generator = new BusinessDocumentGenerator();
        $this->assertTrue($generator->generate($albaran, 'FacturaCliente', [$linea], [$linea->idlinea => 5]), 'can-not-generate-document');
        $this->runWorkQueue();

        // ahora comprobamos que la factura se ha creado
        $facturas = $generator->getLastDocs();
        $this->assertCount(1, $facturas);

        // comprobamos que hay 2 movimientos de stock
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(2, $movimientos);

        // el albarán conserva el movimiento histórico y la factura solo aporta el delta
        $deliveryMovement = new MovimientoStock();
        $whereDelivery = array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]);
        $this->assertTrue($deliveryMovement->loadWhere($whereDelivery));
        $this->assertEquals(-10, $deliveryMovement->cantidad);

        $invoiceMovement = new MovimientoStock();
        $whereInvoice = array_merge($whereRef, [
            Where::eq('docid', $facturas[0]->id()),
            Where::eq('docmodel', $facturas[0]->modelClassName())
        ]);
        $this->assertTrue($invoiceMovement->loadWhere($whereInvoice));
        $this->assertEquals(0, $invoiceMovement->cantidad);

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testStatusAlbaranClienteGeneratesInvoice(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaCliente' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId);

        // al cambiar el estado se genera la factura
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // comprobamos que la factura se ha creado
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);
        $this->assertEquals($warehouse->codalmacen, $facturas[0]->codalmacen);

        // comprobamos que el stock final no cambia
        $this->assertTrue($stock->reload());
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);

        // el albarán conserva el histórico y la factura refleja solo el delta
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(2, $movimientos);

        $deliveryMovement = new MovimientoStock();
        $whereDelivery = array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]);
        $this->assertTrue($deliveryMovement->loadWhere($whereDelivery));
        $this->assertEquals(-10, $deliveryMovement->cantidad);

        $invoiceMovement = new MovimientoStock();
        $whereInvoice = array_merge($whereRef, [
            Where::eq('docid', $facturas[0]->id()),
            Where::eq('docmodel', $facturas[0]->modelClassName())
        ]);
        $this->assertTrue($invoiceMovement->loadWhere($whereInvoice));
        $this->assertEquals(0, $invoiceMovement->cantidad);
        $this->assertEquals(-10, $invoiceMovement->saldo);

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testPartialAlbaranProveedor(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // actualizamos los totales
        $lines = $albaran->getLines();
        $this->assertTrue(Calculator::calculate($albaran, $lines, true));

        // facturamos parcialmente el albarán
        $generator = new BusinessDocumentGenerator();
        $this->assertTrue($generator->generate($albaran, 'FacturaProveedor', [$linea], [$linea->idlinea => 5]), 'can-not-generate-document');
        $this->runWorkQueue();

        // ahora comprobamos que la factura se ha creado
        $facturas = $generator->getLastDocs();
        $this->assertCount(1, $facturas);

        // comprobamos que hay 2 movimientos de stock
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(2, $movimientos);

        // el albarán conserva el movimiento histórico y la factura solo aporta el delta
        $deliveryMovement = new MovimientoStock();
        $whereDelivery = array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]);
        $this->assertTrue($deliveryMovement->loadWhere($whereDelivery));
        $this->assertEquals(10, $deliveryMovement->cantidad);

        $invoiceMovement = new MovimientoStock();
        $whereInvoice = array_merge($whereRef, [
            Where::eq('docid', $facturas[0]->id()),
            Where::eq('docmodel', $facturas[0]->modelClassName())
        ]);
        $this->assertTrue($invoiceMovement->loadWhere($whereInvoice));
        $this->assertEquals(0, $invoiceMovement->cantidad);

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testStatusAlbaranProveedorGeneratesInvoice(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId);

        // al cambiar el estado se genera la factura
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // comprobamos que la factura se ha creado
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);
        $this->assertEquals($warehouse->codalmacen, $facturas[0]->codalmacen);

        // comprobamos que el stock final no cambia
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(10, $stock->cantidad);
        $this->assertEquals(10, $stock->disponible);

        // el albarán conserva el histórico y la factura refleja solo el delta
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(2, $movimientos);

        $deliveryMovement = new MovimientoStock();
        $whereDelivery = array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]);
        $this->assertTrue($deliveryMovement->loadWhere($whereDelivery));
        $this->assertEquals(10, $deliveryMovement->cantidad);

        $invoiceMovement = new MovimientoStock();
        $whereInvoice = array_merge($whereRef, [
            Where::eq('docid', $facturas[0]->id()),
            Where::eq('docmodel', $facturas[0]->modelClassName())
        ]);
        $this->assertTrue($invoiceMovement->loadWhere($whereInvoice));
        $this->assertEquals(0, $invoiceMovement->cantidad);
        $this->assertEquals(10, $invoiceMovement->saldo);

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testStatusAlbaranProveedorGeneratesInvoiceAfterCounting(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());
        $this->runWorkQueue();

        // hacemos un conteo posterior, dejando el stock real en 6
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save());

        $lineaConteo = $conteo->addLine($product->referencia, $product->idproducto, 6);
        $this->assertTrue($lineaConteo->exists());
        $this->assertTrue($conteo->updateStock());

        // comprobamos que el stock real ya está en 6
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(6, $stock->cantidad);
        $this->assertEquals(6, $stock->disponible);

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId);

        // al cambiar el estado se genera la factura
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // comprobamos que la factura se ha creado
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);
        $this->assertEquals($warehouse->codalmacen, $facturas[0]->codalmacen);

        // el stock real final debe seguir en 6
        $this->assertTrue($stock->reload());
        $this->assertEquals(6, $stock->cantidad);
        $this->assertEquals(6, $stock->disponible);

        // el movimiento del conteo sigue existiendo
        $countMovement = new MovimientoStock();
        $whereCount = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $conteo->id()),
            Where::eq('docmodel', $conteo->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($countMovement->loadWhere($whereCount));
        $this->assertEquals(0, $countMovement->cantidad);
        $this->assertEquals(6, $countMovement->saldo);

        // el albarán conserva el histórico previo al conteo
        $deliveryMovement = new MovimientoStock();
        $whereDelivery = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($deliveryMovement->loadWhere($whereDelivery));
        $this->assertEquals(10, $deliveryMovement->cantidad);
        $this->assertEquals(10, $deliveryMovement->saldo);

        // la factura debe reflejar solo el delta y mantener el saldo real final
        $invoiceMovement = new MovimientoStock();
        $whereInvoice = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $facturas[0]->id()),
            Where::eq('docmodel', $facturas[0]->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($invoiceMovement->loadWhere($whereInvoice));
        $this->assertEquals(0, $invoiceMovement->cantidad);
        $this->assertEquals(6, $invoiceMovement->saldo);

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($conteo->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testStatusAlbaranProveedorInvoiceMovementTracksQuantityIncreaseAfterCounting(): void
    {
        $scenario = $this->createSupplierInvoiceAfterCountingScenario();
        $invoiceLine = $scenario['invoice']->getLines()[0];
        $invoiceLine->cantidad = 12;
        $this->assertTrue($invoiceLine->save());
        $this->runWorkQueue();

        $this->assertTrue($scenario['stock']->reload());
        $this->assertEquals(8, $scenario['stock']->cantidad);
        $this->assertEquals(8, $scenario['stock']->disponible);

        $invoiceMovement = new MovimientoStock();
        $whereInvoice = [
            Where::eq('codalmacen', $scenario['warehouse']->codalmacen),
            Where::eq('docid', $scenario['invoice']->id()),
            Where::eq('docmodel', $scenario['invoice']->modelClassName()),
            Where::eq('referencia', $scenario['product']->referencia)
        ];
        $this->assertTrue($invoiceMovement->loadWhere($whereInvoice));
        $this->assertEquals(2, $invoiceMovement->cantidad);
        $this->assertEquals(8, $invoiceMovement->saldo);

        $this->deleteSupplierInvoiceAfterCountingScenario($scenario);
    }

    public function testStatusAlbaranProveedorInvoiceMovementTracksQuantityDecreaseAfterCounting(): void
    {
        $scenario = $this->createSupplierInvoiceAfterCountingScenario();
        $invoiceLine = $scenario['invoice']->getLines()[0];
        $invoiceLine->cantidad = 8;
        $this->assertTrue($invoiceLine->save());
        $this->runWorkQueue();

        $this->assertTrue($scenario['stock']->reload());
        $this->assertEquals(4, $scenario['stock']->cantidad);
        $this->assertEquals(4, $scenario['stock']->disponible);

        $invoiceMovement = new MovimientoStock();
        $whereInvoice = [
            Where::eq('codalmacen', $scenario['warehouse']->codalmacen),
            Where::eq('docid', $scenario['invoice']->id()),
            Where::eq('docmodel', $scenario['invoice']->modelClassName()),
            Where::eq('referencia', $scenario['product']->referencia)
        ];
        $this->assertTrue($invoiceMovement->loadWhere($whereInvoice));
        $this->assertEquals(-2, $invoiceMovement->cantidad);
        $this->assertEquals(4, $invoiceMovement->saldo);

        $this->deleteSupplierInvoiceAfterCountingScenario($scenario);
    }

    public function testCreateAlbaranProveedor(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(10, $stock->cantidad);
        $this->assertEquals(10, $stock->disponible);
        $this->assertEquals(0, $stock->pterecibir);
        $this->assertEquals(0, $stock->reservada);

        // comprobamos que hay un movimiento de stock
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals(10, $movement->cantidad);
        $this->assertEquals(10, $movement->saldo);
        $this->assertEquals($albaran->id(), $movement->docid);
        $this->assertEquals($albaran->modelClassName(), $movement->docmodel);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranProveedorNostockProductDoesNotGenerateMovement(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto que no gestiona stock
        $product = $this->getRandomProduct();
        $product->nostock = true;
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // no debe existir registro de stock para este producto
        $stock = new Stock();
        $this->assertFalse($stock->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));

        // no debe existir movimiento de stock para este albarán
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia),
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]));

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranProveedorReturnedStatusRemovesMovement(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que hay stock del producto y movimiento
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(10, $stock->cantidad);

        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals(10, $movement->cantidad);

        // buscamos un estado devuelto (editable = false, actualizastock = 0, generadoc vacío)
        $returnedStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if (false === (bool)$status->editable
                && 0 === (int)$status->actualizastock
                && empty($status->generadoc)) {
                $returnedStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($returnedStatusId, 'no-returned-status-found');

        // cambiamos el albarán al estado devuelto
        $albaran->idestado = $returnedStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // el stock vuelve a 0
        if ($stock->reload()) {
            $this->assertEquals(0, $stock->cantidad);
            $this->assertEquals(0, $stock->disponible);
        }

        // no debe quedar movimiento de stock para este albarán
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere(array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ])));

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAlbaranProveedorReturnedThenInvoicedAndInvoiceDeleted(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // buscamos el estado devuelto y el estado que genera factura
        $returnedStatusId = null;
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if (false === (bool)$status->editable
                && 0 === (int)$status->actualizastock
                && empty($status->generadoc)) {
                $returnedStatusId = $status->idestado;
            }
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
            }
        }
        $this->assertNotNull($returnedStatusId, 'no-returned-status-found');
        $this->assertNotNull($invoiceStatusId, 'no-invoice-status-found');

        // pasamos el albarán a devuelto
        $albaran->idestado = $returnedStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // no hay movimiento de stock para el albarán
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $whereAlbaran = array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ]);
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereAlbaran));

        // ahora pasamos el albarán al estado que genera factura
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $this->runWorkQueue();

        // se ha creado la factura
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);

        // hay 2 movimientos: el del albarán (+10) y el de la factura (delta 0)
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(2, $movimientos);

        // borramos la factura
        $this->assertTrue($facturas[0]->delete());
        $this->runWorkQueue();

        // el albarán debe volver al estado devuelto
        $this->assertTrue($albaran->reload());
        $this->assertEquals($returnedStatusId, $albaran->idestado);

        // por tanto NO debe quedar movimiento de stock para el albarán
        $movement = new MovimientoStock();
        $this->assertFalse(
            $movement->loadWhere($whereAlbaran),
            'el albarán en estado devuelto no debe tener movimiento de stock'
        );

        // y el stock debe estar en 0
        $stock = new Stock();
        if ($stock->loadWhere($whereRef)) {
            $this->assertEquals(0, $stock->cantidad);
            $this->assertEquals(0, $stock->disponible);
        }

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testPerformanceAlbaranProveedor200LineasMismaReferencia(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos 200 líneas con la misma referencia
        $numLines = 200;
        for ($num = 0; $num < $numLines; $num++) {
            $linea = $albaran->getNewProductLine($product->referencia);
            $linea->cantidad = 1;
            $linea->pvpunitario = 1;
            $this->assertTrue($linea->save());
        }

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId);

        // medimos el tiempo que tarda en pasar a factura
        $start = microtime(true);
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $elapsed = microtime(true) - $start;
        $this->runWorkQueue();

        fwrite(STDERR, PHP_EOL . '200 líneas misma referencia -> factura: ' . round($elapsed, 2) . ' s' . PHP_EOL);

        // comprobamos que la factura se ha creado
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);

        // sólo debe haber 2 movimientos: el del albarán y el de la factura
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertCount(2, MovimientoStock::all($whereRef, [], 0, 0));

        $deliveryMovement = new MovimientoStock();
        $this->assertTrue($deliveryMovement->loadWhere(array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ])));
        $this->assertEquals($numLines, $deliveryMovement->cantidad);
        $this->assertEquals($numLines, $deliveryMovement->saldo);

        $invoiceMovement = new MovimientoStock();
        $this->assertTrue($invoiceMovement->loadWhere(array_merge($whereRef, [
            Where::eq('docid', $facturas[0]->id()),
            Where::eq('docmodel', $facturas[0]->modelClassName())
        ])));
        $this->assertEquals(0, $invoiceMovement->cantidad);
        $this->assertEquals($numLines, $invoiceMovement->saldo);

        // el proceso debe ser rápido
        $this->assertLessThan(self::perfBudget(25.0), $elapsed, 'el paso a factura es demasiado lento');

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testPerformanceAlbaranProveedor50ProductosConHistorico(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos 50 productos, cada uno con entre 200 y 1000 movimientos previos
        $numProducts = 50;
        $products = [];
        $history = [];
        for ($num = 0; $num < $numProducts; $num++) {
            $product = $this->getRandomProduct();
            $this->assertTrue($product->save());
            $products[] = $product;

            $history[$product->referencia] = $this->createFakeMovements(
                $product, $warehouse->codalmacen, mt_rand(200, 1000), -1 - $num
            );
        }

        try {
            // creamos el albarán con una línea por producto
            $albaran = new AlbaranProveedor();
            $this->assertTrue($albaran->setSubject($proveedor));
            $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
            $this->assertTrue($albaran->save());

            foreach ($products as $product) {
                $linea = $albaran->getNewProductLine($product->referencia);
                $linea->cantidad = 1;
                $linea->pvpunitario = 1;
                $this->assertTrue($linea->save());
            }

            // buscamos el estado que genera factura
            $invoiceStatusId = null;
            foreach ($albaran->getAvailableStatus() as $status) {
                if ('FacturaProveedor' === $status->generadoc) {
                    $invoiceStatusId = $status->idestado;
                    break;
                }
            }
            $this->assertNotNull($invoiceStatusId);

            // medimos el tiempo que tarda en pasar a factura
            $start = microtime(true);
            $albaran->idestado = $invoiceStatusId;
            $this->assertTrue($albaran->save());
            $elapsed = microtime(true) - $start;
            $this->runWorkQueue();

            fwrite(STDERR, PHP_EOL . '50 productos con histórico -> factura: ' . round($elapsed, 2) . ' s' . PHP_EOL);

            // comprobamos que la factura se ha creado
            $facturas = $albaran->childrenDocuments();
            $this->assertCount(1, $facturas);

            // comprobamos los movimientos de cada producto
            foreach ($products as $product) {
                $total = $history[$product->referencia];
                $whereRef = [
                    Where::eq('codalmacen', $warehouse->codalmacen),
                    Where::eq('referencia', $product->referencia)
                ];
                $this->assertCount($total + 2, MovimientoStock::all($whereRef, [], 0, 0));

                $deliveryMovement = new MovimientoStock();
                $this->assertTrue($deliveryMovement->loadWhere(array_merge($whereRef, [
                    Where::eq('docid', $albaran->id()),
                    Where::eq('docmodel', $albaran->modelClassName())
                ])));
                $this->assertEquals(1, $deliveryMovement->cantidad);
                $this->assertEquals($total + 1, $deliveryMovement->saldo);

                $invoiceMovement = new MovimientoStock();
                $this->assertTrue($invoiceMovement->loadWhere(array_merge($whereRef, [
                    Where::eq('docid', $facturas[0]->id()),
                    Where::eq('docmodel', $facturas[0]->modelClassName())
                ])));
                $this->assertEquals(0, $invoiceMovement->cantidad);
                $this->assertEquals($total + 1, $invoiceMovement->saldo);
            }

            // el proceso debe ser rápido
            $this->assertLessThan(self::perfBudget(30.0), $elapsed, 'el paso a factura es demasiado lento');

            // eliminamos
            $this->assertTrue($facturas[0]->delete());
            $this->assertTrue($albaran->delete());
        } finally {
            // eliminamos los movimientos ficticios
            $db = new DataBase();
            $db->exec('DELETE FROM stocks_movimientos WHERE docid < 0;');
        }

        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        foreach ($products as $product) {
            $this->assertTrue($product->delete());
        }
        $this->assertTrue($warehouse->delete());
    }

    public function testPerformanceAgrupar120AlbaranesProveedorEnUnaFactura(): void
    {
        // semilla fija para que el test sea reproducible
        mt_srand(20260917);

        // nos aseguramos de que la tabla de logs existe: al borrar la factura se escribe
        // el log de auditoría dentro de una transacción, y ahí no se puede crear la tabla
        new LogMessage();

        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos los productos que se repetirán entre los albaranes,
        // cada uno con entre 0 y 500 movimientos previos
        $numProducts = 40;
        $products = [];
        $history = [];
        for ($num = 0; $num < $numProducts; $num++) {
            $product = $this->getRandomProduct();
            $this->assertTrue($product->save());
            $products[] = $product;

            $history[$product->referencia] = $this->createFakeMovements(
                $product, $warehouse->codalmacen, mt_rand(0, 500), -1 - $num
            );
        }

        $albaranes = [];
        $expectedQuantity = [];
        $expectedMovements = [];
        $totalLines = 0;

        try {
            // creamos 120 albaranes con entre 1 y 50 líneas cada uno
            $numAlbaranes = 120;
            for ($num = 0; $num < $numAlbaranes; $num++) {
                $albaran = new AlbaranProveedor();
                $this->assertTrue($albaran->setSubject($proveedor));
                $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
                $this->assertTrue($albaran->save());

                $numLines = mt_rand(1, 50);
                $refsInDoc = [];
                for ($pos = 0; $pos < $numLines; $pos++) {
                    $product = $products[mt_rand(0, $numProducts - 1)];
                    $cantidad = mt_rand(1, 5);

                    $linea = $albaran->getNewProductLine($product->referencia);
                    $linea->cantidad = $cantidad;
                    $linea->pvpunitario = 1;
                    $this->assertTrue($linea->save());

                    $expectedQuantity[$product->referencia] = ($expectedQuantity[$product->referencia] ?? 0) + $cantidad;
                    $refsInDoc[$product->referencia] = true;
                    $totalLines++;
                }

                // cada albarán genera un movimiento por cada referencia distinta que contiene
                foreach (array_keys($refsInDoc) as $referencia) {
                    $expectedMovements[$referencia] = ($expectedMovements[$referencia] ?? 0) + 1;
                }

                $albaranes[] = $albaran;
            }

            // buscamos el estado que genera factura
            $invoiceStatusId = null;
            foreach ($albaranes[0]->getAvailableStatus() as $status) {
                if ('FacturaProveedor' === $status->generadoc) {
                    $invoiceStatusId = $status->idestado;
                    break;
                }
            }
            $this->assertNotNull($invoiceStatusId, 'no-invoice-status-found');

            // agrupamos todos los albaranes en una sola factura,
            // reproduciendo el flujo del controlador DocumentStitcher
            $start = microtime(true);
            $newLines = [];
            $quantities = [];
            $prototype = null;

            // el controlador DocumentStitcher agrupa toda la operación en un único lote
            $batchOwner = 'test-stitcher';
            StockMovementManager::beginBatch($batchOwner);
            try {
                foreach ($albaranes as $albaran) {
                    $lines = $albaran->getLines();
                    if (null === $prototype) {
                        $prototype = clone $albaran;
                    }

                    foreach ($lines as $line) {
                        $quantities[$line->id()] = (float)$line->cantidad - (float)$line->servido;
                        $newLines[] = $line;
                    }

                    $albaran->setDocumentGeneration(false);
                    $albaran->idestado = $invoiceStatusId;
                    $this->assertTrue($albaran->save());

                    // reponemos las líneas con las versiones frescas, igual que DocumentStitcher
                    foreach ($albaran->getLines() as $fresh) {
                        foreach ($newLines as $pos => $newLine) {
                            if ($newLine->id() === $fresh->id()) {
                                $newLines[$pos] = $fresh;
                                break;
                            }
                        }
                    }
                }

                $generator = new BusinessDocumentGenerator();
                $this->assertTrue($generator->generate($prototype, 'FacturaProveedor', $newLines, $quantities));
            } finally {
                StockMovementManager::flushBatch($batchOwner);

                // es un flag estático, hay que restaurarlo siempre
                $albaranes[0]->setDocumentGeneration(true);
            }
            $elapsed = microtime(true) - $start;
            $this->runWorkQueue();

            fwrite(STDERR, PHP_EOL . '120 albaranes (' . $totalLines . ' líneas) -> 1 factura: '
                . round($elapsed, 2) . ' s' . PHP_EOL);

            // se ha creado una única factura con todas las líneas
            $facturas = $generator->getLastDocs();
            $this->assertCount(1, $facturas);
            $factura = $facturas[0];
            $this->assertEquals($warehouse->codalmacen, $factura->codalmacen);
            $this->assertCount($totalLines, $factura->getLines());

            // todos los albaranes apuntan a esa factura
            foreach ($albaranes as $albaran) {
                $children = $albaran->childrenDocuments();
                $this->assertCount(1, $children);
                $this->assertEquals($factura->id(), $children[0]->id());
            }

            // comprobamos los movimientos de cada producto
            foreach ($products as $product) {
                $referencia = $product->referencia;
                $previous = $history[$referencia];
                $whereRef = [
                    Where::eq('codalmacen', $warehouse->codalmacen),
                    Where::eq('referencia', $referencia)
                ];

                // si el producto no ha entrado en ningún albarán, sólo tiene su histórico
                if (false === isset($expectedQuantity[$referencia])) {
                    $this->assertCount($previous, MovimientoStock::all($whereRef, [], 0, 0));
                    continue;
                }

                // histórico + un movimiento por albarán que lo contiene + el de la factura
                $this->assertCount(
                    $previous + $expectedMovements[$referencia] + 1,
                    MovimientoStock::all($whereRef, [], 0, 0),
                    'movimientos incorrectos en ' . $referencia
                );

                // el movimiento de la factura es el delta (0) y cierra el saldo acumulado
                $invoiceMovement = new MovimientoStock();
                $this->assertTrue($invoiceMovement->loadWhere(array_merge($whereRef, [
                    Where::eq('docid', $factura->id()),
                    Where::eq('docmodel', $factura->modelClassName())
                ])));
                $this->assertEquals(0, $invoiceMovement->cantidad, 'la factura no debe mover stock en ' . $referencia);
                $this->assertEquals(
                    $previous + $expectedQuantity[$referencia],
                    $invoiceMovement->saldo,
                    'saldo incorrecto en ' . $referencia
                );

                // el stock refleja todo lo recibido en los albaranes
                $stock = new Stock();
                $this->assertTrue($stock->loadWhere($whereRef));
                $this->assertEquals($expectedQuantity[$referencia], $stock->cantidad, 'stock incorrecto en ' . $referencia);
            }

            // el proceso debe ser fluido. El grueso del tiempo restante lo consume el core
            // al clonar las líneas, así que el margen es amplio y depende de la carga de la máquina
            $this->assertLessThan(self::perfBudget(150.0), $elapsed, 'la agrupación de albaranes es demasiado lenta');

            // eliminamos
            $this->assertTrue($factura->delete());
            foreach ($albaranes as $albaran) {
                $this->assertTrue($albaran->delete());
            }
        } finally {
            // eliminamos los movimientos ficticios
            $db = new DataBase();
            $db->exec('DELETE FROM stocks_movimientos WHERE docid < 0;');
        }

        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        foreach ($products as $product) {
            $this->assertTrue($product->delete());
        }
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }

    /**
     * Inserta movimientos de stock ficticios para simular un histórico largo.
     * Se insertan directamente en la tabla porque crearlos con documentos sería inviable.
     *
     * @param object $product producto al que pertenecen los movimientos
     * @param string $codalmacen almacén de los movimientos
     * @param int $total número de movimientos a crear
     * @param int $docid identificador ficticio de documento, siempre negativo
     *
     * @return int número de movimientos creados
     */
    private function createFakeMovements($product, string $codalmacen, int $total, int $docid): int
    {
        $db = new DataBase();
        $values = [];
        for ($num = 1; $num <= $total; $num++) {
            // las fechas van hacia atrás, para que el albarán nuevo quede siempre el último
            $fecha = date('Y-m-d', strtotime('-' . ($total - $num + 1) . ' days'));
            $values[] = '(1, ' . $db->var2str($codalmacen) . ', ' . $docid . ", 'AlbaranProveedor', 'Test',"
                . " '" . $fecha . "', '00:00:00', " . $product->idproducto . ', '
                . $db->var2str($product->referencia) . ', ' . $num . ')';

            // insertamos por bloques de 500 filas
            if (count($values) === 500 || $num === $total) {
                $sql = 'INSERT INTO stocks_movimientos'
                    . ' (cantidad, codalmacen, docid, docmodel, documento, fecha, hora, idproducto, referencia, saldo)'
                    . ' VALUES ' . implode(', ', $values) . ';';
                $this->assertTrue($db->exec($sql));
                $values = [];
            }
        }

        return $total;
    }

    /**
     * Devuelve el tiempo máximo aceptable, en segundos, para las pruebas de rendimiento.
     * Se puede ajustar con la variable de entorno STOCKAVANZADO_PERF_BUDGET.
     *
     * @param float $default valor por defecto
     *
     * @return float
     */
    private static function perfBudget(float $default): float
    {
        return (float)(getenv('STOCKAVANZADO_PERF_BUDGET') ?: $default);
    }

    private function createSupplierInvoiceAfterCountingScenario(): array
    {
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $supplier = $this->getRandomSupplier();
        $this->assertTrue($supplier->save());

        $delivery = new AlbaranProveedor();
        $this->assertTrue($delivery->setSubject($supplier));
        $this->assertTrue($delivery->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($delivery->save());

        $line = $delivery->getNewProductLine($product->referencia);
        $line->cantidad = 10;
        $line->pvpunitario = 10;
        $this->assertTrue($line->save());
        $this->runWorkQueue();

        $count = new ConteoStock();
        $count->codalmacen = $warehouse->codalmacen;
        $count->observaciones = 'Test';
        $this->assertTrue($count->save());

        $countLine = $count->addLine($product->referencia, $product->idproducto, 6);
        $this->assertTrue($countLine->exists());
        $this->assertTrue($count->updateStock());

        $invoiceStatusId = null;
        foreach ($delivery->getAvailableStatus() as $status) {
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId);

        $delivery->idestado = $invoiceStatusId;
        $this->assertTrue($delivery->save());
        $this->runWorkQueue();

        $invoices = $delivery->childrenDocuments();
        $this->assertCount(1, $invoices);

        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));

        return [
            'conteo' => $count,
            'invoice' => $invoices[0],
            'product' => $product,
            'stock' => $stock,
            'supplier' => $supplier,
            'delivery' => $delivery,
            'warehouse' => $warehouse,
        ];
    }

    private function deleteSupplierInvoiceAfterCountingScenario(array $scenario): void
    {
        $this->assertTrue($scenario['invoice']->delete());
        $this->assertTrue($scenario['conteo']->delete());
        $this->assertTrue($scenario['delivery']->delete());
        $this->assertTrue($scenario['supplier']->delete());
        $this->assertTrue($scenario['supplier']->getDefaultAddress()->delete());
        $this->assertTrue($scenario['product']->delete());
        $this->assertTrue($scenario['warehouse']->delete());
    }

    private function runWorkQueue(): void
    {
        while (WorkQueue::run()) {
        }
    }
}
