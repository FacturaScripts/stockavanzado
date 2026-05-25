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
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PedidoProveedor;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
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

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
