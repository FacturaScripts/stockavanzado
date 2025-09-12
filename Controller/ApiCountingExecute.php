<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\Controller;

use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Core\Response;

class ApiCountingExecute extends ApiController
{
    protected function runResource(): void
    {
        // si la llamada no es post, devolvemos un error
        if ('POST' !== $this->request->method()) {
            $this->response->setHttpCode(Response::HTTP_METHOD_NOT_ALLOWED);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Method not allowed',
            ]));
            return;
        }

        // cargamos el conteo
        $counting = new ConteoStock();
        if (false === $counting->load($this->request->get('code'))) {
            $this->response->setHttpCode(Response::HTTP_NOT_FOUND);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Counting not found',
            ]));
            return;
        }

        // si el conteo ya está completado, devolvemos un error
        if ($counting->completed) {
            $this->response->setHttpCode(Response::HTTP_BAD_REQUEST);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Counting already completed',
            ]));
            return;
        }

        // ejecutamos el conteo
        if (false === $counting->updateStock()) {
            $this->response->setHttpCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Error executing counting',
            ]));
            return;
        }

        // devolvemos respuesta
        $this->response->setContent(json_encode([
            'status' => 'ok',
            'message' => 'Counting executed',
        ]));
    }
}