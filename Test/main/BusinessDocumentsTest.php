<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2024-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Lib\Calculator;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PedidoProveedor;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoProveedor;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class BusinessDocumentsTest extends TestCase
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
        $linea->servido = 5;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // actualizamos los totales
        $lines = $albaran->getLines();
        $this->assertTrue(Calculator::calculate($albaran, $lines, true));

        // probamos parcialmente el albarán
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
        $linea->servido = 5;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // actualizamos los totales
        $lines = $albaran->getLines();
        $this->assertTrue(Calculator::calculate($albaran, $lines, true));

        // probamos parcialmente el albarán
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

    public function testCreatePedidoCliente(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un pedido en ese almacén
        $pedido = new PedidoCliente();
        $this->assertTrue($pedido->setSubject($customer));
        $this->assertTrue($pedido->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($pedido->save());

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);
        $this->assertEquals(0, $stock->pterecibir);
        $this->assertEquals(10, $stock->reservada);

        // comprobamos que no hay ningún movimiento de stock
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereRef));

        // eliminamos
        $this->assertTrue($pedido->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCreatePedidoProveedor(): void
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

        // creamos un pedido en ese almacén
        $pedido = new PedidoProveedor();
        $this->assertTrue($pedido->setSubject($proveedor));
        $this->assertTrue($pedido->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($pedido->save());

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);
        $this->assertEquals(10, $stock->pterecibir);
        $this->assertEquals(0, $stock->reservada);

        // comprobamos que no hay ningún movimiento de stock
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereRef));

        // eliminamos
        $this->assertTrue($pedido->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testStatusPedidoClienteGeneratesAlbaran(): void
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

        // creamos un pedido en ese almacén
        $pedido = new PedidoCliente();
        $this->assertTrue($pedido->setSubject($customer));
        $this->assertTrue($pedido->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($pedido->save());

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que aún no hay movimiento de stock
        $movement = new MovimientoStock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($movement->loadWhere($whereRef));

        // buscamos el estado del pedido que genera albarán
        $deliveryStatusId = null;
        foreach ($pedido->getAvailableStatus() as $status) {
            if ('AlbaranCliente' === $status->generadoc) {
                $deliveryStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($deliveryStatusId);

        // al cambiar el estado se genera el albarán
        $pedido->idestado = $deliveryStatusId;
        $this->assertTrue($pedido->save());

        // comprobamos que el albarán se ha creado
        $albaranes = $pedido->childrenDocuments();
        $this->assertCount(1, $albaranes);
        $this->assertEquals($warehouse->codalmacen, $albaranes[0]->codalmacen);

        // comprobamos que el stock se ha reducido
        $this->assertTrue($stock->reload());
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);

        // comprobamos que el movimiento de stock pertenece al albarán generado
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(1, $movimientos);
        $this->assertEquals(-10, $movimientos[0]->cantidad);
        $this->assertEquals($albaranes[0]->id(), $movimientos[0]->docid);
        $this->assertEquals($albaranes[0]->modelClassName(), $movimientos[0]->docmodel);

        // eliminamos
        $this->assertTrue($albaranes[0]->delete());
        $this->assertTrue($pedido->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testStatusPedidoProveedorGeneratesAlbaran(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto (sin stock inicial)
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos un pedido en ese almacén
        $pedido = new PedidoProveedor();
        $this->assertTrue($pedido->setSubject($proveedor));
        $this->assertTrue($pedido->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($pedido->save());

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que aún no hay movimiento de stock
        $movement = new MovimientoStock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($movement->loadWhere($whereRef));

        // buscamos el estado del pedido que genera albarán
        $deliveryStatusId = null;
        foreach ($pedido->getAvailableStatus() as $status) {
            if ('AlbaranProveedor' === $status->generadoc) {
                $deliveryStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($deliveryStatusId);

        // al cambiar el estado se genera el albarán
        $pedido->idestado = $deliveryStatusId;
        $this->assertTrue($pedido->save());

        // comprobamos que el albarán se ha creado
        $albaranes = $pedido->childrenDocuments();
        $this->assertCount(1, $albaranes);
        $this->assertEquals($warehouse->codalmacen, $albaranes[0]->codalmacen);

        // comprobamos que el stock ha aumentado
        $stock = new Stock();
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(10, $stock->cantidad);
        $this->assertEquals(10, $stock->disponible);

        // comprobamos que el movimiento de stock pertenece al albarán generado
        $movimientos = MovimientoStock::all($whereRef);
        $this->assertCount(1, $movimientos);
        $this->assertEquals(10, $movimientos[0]->cantidad);
        $this->assertEquals($albaranes[0]->id(), $movimientos[0]->docid);
        $this->assertEquals($albaranes[0]->modelClassName(), $movimientos[0]->docmodel);

        // eliminamos
        $this->assertTrue($albaranes[0]->delete());
        $this->assertTrue($pedido->delete());
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

    public function testCreatePresupuestoCliente(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un presupuesto en ese almacén
        $presupuesto = new PresupuestoCliente();
        $this->assertTrue($presupuesto->setSubject($customer));
        $this->assertTrue($presupuesto->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($presupuesto->save());

        // añadimos una línea al presupuesto
        $linea = $presupuesto->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($stock->loadWhere($whereRef));

        // comprobamos que no hay ningún movimiento de stock
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereRef));

        // eliminamos
        $this->assertTrue($presupuesto->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCreatePresupuestoProveedor(): void
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

        // creamos un presupuesto en ese almacén
        $presupuesto = new PresupuestoProveedor();
        $this->assertTrue($presupuesto->setSubject($proveedor));
        $this->assertTrue($presupuesto->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($presupuesto->save());

        // añadimos una línea al presupuesto
        $linea = $presupuesto->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($stock->loadWhere($whereRef));

        // comprobamos que no hay ningún movimiento de stock
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereRef));

        // eliminamos
        $this->assertTrue($presupuesto->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }

    private function runWorkQueue(): void
    {
        while (WorkQueue::run()) {
        }
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
}
