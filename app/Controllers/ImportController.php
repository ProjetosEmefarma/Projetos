<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ImportService;

final class ImportController
{
    public function template(Request $request): Response
    {
        $type = $request->param('type');
        if (!in_array($type, ['items', 'requests'], true)) {
            throw HttpException::notFound('Modelo não encontrado.');
        }
        $bin = ImportService::template($type);
        $name = $type === 'items' ? 'modelo-importacao-brindes.xlsx' : 'modelo-importacao-solicitacoes.xlsx';
        return Response::download($bin, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function preview(Request $request): Response
    {
        $type = $request->param('type');
        $file = $request->file('file');
        if ($file === null) {
            throw HttpException::validation(['file' => 'Selecione a planilha.']);
        }
        return Response::ok(ImportService::preview($type, $file));
    }

    public function commit(Request $request): Response
    {
        $type = $request->param('type');
        $file = $request->file('file');
        if ($file === null) {
            throw HttpException::validation(['file' => 'Selecione a planilha.']);
        }
        return Response::ok(ImportService::commit($type, $file));
    }
}
