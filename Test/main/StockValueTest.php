<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\StockValueManager;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\StockValoradoHistorico;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class StockValueTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testCreate(): void
    {
        // creamos un almacén
        $warehouse = new Almacen();
        $warehouse->nombre = 'Warehouse ' . mt_rand(1, 99);
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // obtenemos la primera variante
        $variante = $product->getVariants()[0];

        // ponemos el precio y coste
        $variante->coste = 10.0;
        $variante->precio = 20.0;
        $this->assertTrue($variante->save());

        // añadimos stock del producto al almacén
        $stock = new Stock();
        $stock->cantidad = 10;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $this->assertTrue($stock->save());

        // ejecutamos la clase StockValue
        StockValueManager::calculate($warehouse->id());

        // obtenemos el historial del almacén
        $hist = new StockValoradoHistorico();
        $whereHist = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('fecha', Tools::date())
        ];
        $this->assertTrue($hist->loadWhere($whereHist), 'No se ha encontrado el histórico del almacén');

        // comprobamos que el coste y precio valorado son correctos
        $this->assertEquals(100.0, $hist->total_coste, 'El coste valorado no es correcto');
        $this->assertEquals(200.0, $hist->total_precio, 'El precio valorado no es correcto');

        // eliminamos
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }
}
