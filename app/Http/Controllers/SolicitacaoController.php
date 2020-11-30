<?php

namespace App\Http\Controllers;

use App\HistoricoStatus;
use App\ItemSolicitacao;
use App\material;
use App\Solicitacao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use phpDocumentor\Reflection\Types\Self_;

class SolicitacaoController extends Controller
{
    public function show()
    {
        $materiais = material::orderBy('id')->get();
        return view('solicitacao.solicita_material', ['materiais' => $materiais]);
    }

    public function store(Request $request)
    {
        if (empty($request->dataTableMaterial) || empty($request->dataTableQuantidade)) {
            return redirect()->back()->withErrors('Adicione o(s) material(is) e sua(s) quantidade(s)');
        } else {
            $materiais = explode(",",  $request->dataTableMaterial);
            $quantidades = explode(",",  $request->dataTableQuantidade);

            $solicitacao = new Solicitacao();
            $solicitacao->usuario_id = Auth::user()->id;
            $solicitacao->observacao_requerente = $request->observacao;
            if ($request->checkReceptor == NULL) {
                $solicitacao->receptor = $request->nomeReceptor;
                $solicitacao->receptor_rg = $request->rgReceptor;
            }
            $solicitacao->save();

            $historicoStatus = new HistoricoStatus();
            $historicoStatus->status = "Aguardando Analise";
            $historicoStatus->solicitacao_id = $solicitacao->id;
            $historicoStatus->save();

            for ($i = 0; $i < count($materiais); $i++) {
                $itemSolicitacao = new ItemSolicitacao();
                $itemSolicitacao->quantidade_solicitada = $quantidades[$i];
                $itemSolicitacao->material_id = $materiais[$i];
                $itemSolicitacao->solicitacao_id = $solicitacao->id;
                $itemSolicitacao->save();
            }
            return redirect()->back()->with('success', 'Solicitação feita com sucesso!');
        }
    }

    public function aprovarSolicitacao(Request $request)
    {
        $itemSolicitacaos = session('itemSolicitacoes');
        $itensID = [];
        $quantidadesAprovadas = [];
        $materiaisID = [];

        if ($request->action == 'nega') {
            if (is_null($request->observacaoAdmin)) {
                return redirect()->back()->withErrors('Informe o motivo de a solicitação ter sido negada!');
            } else {
                DB::update('update historico_statuses set status = ?, data_finalizado = ? where solicitacao_id = ?', ["Negado", date('Y-m-d H:i:s'), $request->solicitacaoID]);
                DB::update('update solicitacaos set observacao_admin = ? where id = ?', [$request->observacaoAdmin, $request->solicitacaoID]);

                if (session()->exists('itemSolicitacoes')) {
                    session()->forget('itemSolicitacoes');
                }
                if (session()->exists('status')) {
                    session()->forget('status');
                }

                return redirect()->back()->with('success', 'Solicitação cancelada com sucesso!');
            }
        } else if ($request->action == 'aprova') {
            $checkInputNull = 0;
            $checkQuantMin = 0;
            $checkQuant = 0;
            $errorMessage[] = null;

            for ($i = 0; $i < count($itemSolicitacaos); $i++) {
                if (empty($request->quantAprovada[$i]) && $request->quantAprovada[$i] >= 0) {
                    $checkInputNull++;
                } else {
                    if (array_key_exists($itemSolicitacaos[$i]->material_id, $materiaisID)) {
                        $materiaisID[$itemSolicitacaos[$i]->material_id] += $request->quantAprovada[$i];
                    } else if (!array_key_exists($itemSolicitacaos[$i]->material_id, $materiaisID)) {
                        $materiaisID[$itemSolicitacaos[$i]->material_id] = $request->quantAprovada[$i];
                    }

                    if ($materiaisID[$itemSolicitacaos[$i]->material_id] <= $itemSolicitacaos[$i]->quantidade) {
                        array_push($itensID, $itemSolicitacaos[$i]->id);
                        array_push($quantidadesAprovadas, $request->quantAprovada[$i]);
                        $checkQuant = $request->quantAprovada[$i] < $itemSolicitacaos[$i]->quantidade_solicitada;
                    } else {
                        $checkQuantMin++;
                        array_push($errorMessage, $itemSolicitacaos[$i]->nome . "(Dispoível:" . $itemSolicitacaos[$i]->quantidade . ")");
                    }
                }
            }
            if ($checkInputNull == count($itemSolicitacaos)) {
                return redirect()->back()->with('inputNULL', 'Informe os valores das quantidades aprovadas e acima de 0!');
            } else if ($checkQuantMin > 0) {
                return redirect()->back()->withErrors($errorMessage);
            } else {
                for ($i = 0; $i < count($itensID); $i++) {
                    DB::update('update item_solicitacaos set quantidade_aprovada = ? where id = ?', [$quantidadesAprovadas[$i], $itensID[$i]]);
                }

                DB::update(
                    'update historico_statuses set status = ?, data_aprovado = ? where solicitacao_id = ?',
                    [$checkInputNull == 0 && $checkQuant == 0 ? "Aprovado" : "Aprovado Parcialmente", date('Y-m-d H:i:s'), $request->solicitacaoID]
                );

                DB::update('update solicitacaos set observacao_admin = ? where id = ?', [$request->observacaoAdmin, $request->solicitacaoID]);

                if (session()->exists('itemSolicitacoes')) {
                    session()->forget('itemSolicitacoes');
                }
                if (session()->exists('status')) {
                    session()->forget('status');
                }
            }
        }

        return redirect()->back()->with('success', 'Solicitação Aprovada com sucesso!');
    }

    public function listSolicitacoesRequerente()
    {
        $solicitacoes = Solicitacao::where('usuario_id', '=', Auth::user()->id)->get();
        $historicoStatus = HistoricoStatus::whereIn('solicitacao_id', array_column($solicitacoes->toArray(), 'id'))->orderBy('id')->get();
        
        $materiaisPreview = $this->getMateriaisPreview(array_column($historicoStatus->toArray(), 'solicitacao_id'));
        
        return view('solicitacao.minha_solicitacao_requerente', [
            'status' => $historicoStatus, 'materiaisPreview' => $materiaisPreview
        ]);
    }

    public function listSolicitacoesAnalise()
    {
        $consulta = DB::select('select status.status, status.created_at, status.solicitacao_id, u.nome  
            from historico_statuses status, usuarios u, solicitacaos soli 
            where status.data_aprovado IS NULL and status.data_finalizado IS NULL and status.solicitacao_id = soli.id
            and soli.usuario_id = u.id and u.cargo_id != 2 order by status.id desc');

        $materiaisPreview = $this->getMateriaisPreview(array_column($consulta, 'solicitacao_id'));

        return view('solicitacao.analise_solicitacoes', [
            'dados' => $consulta, 'materiaisPreview' => $materiaisPreview
        ]);
    }

    public function listSolicitacoesAprovadas()
    {
        $consulta = DB::select('select status.status, status.created_at, status.solicitacao_id, u.nome  
            from historico_statuses status, usuarios u, solicitacaos soli 
            where status.data_aprovado IS NOT NULL and status.data_finalizado IS NULL and status.solicitacao_id = soli.id
            and soli.usuario_id = u.id and u.cargo_id != 2 order by status.id desc');

        $materiaisPreview = $this->getMateriaisPreview(array_column($consulta, 'solicitacao_id'));

        return view('solicitacao.despache_solicitacao', [
            'dados' => $consulta, 'materiaisPreview' => $materiaisPreview
        ]);
    }

    public function listTodasSolicitacoes()
    {
        $consulta = DB::select('select status.status, status.created_at, status.solicitacao_id, u.nome  
            from historico_statuses status, usuarios u, solicitacaos soli 
            where status.data_finalizado IS NOT NULL and status.solicitacao_id = soli.id
            and soli.usuario_id = u.id and u.cargo_id != 2 order by status.id desc');

        $materiaisPreview = $this->getMateriaisPreview(array_column($consulta, 'solicitacao_id'));

        return view('solicitacao.todas_solicitacao', [
            'dados' => $consulta, 'materiaisPreview' => $materiaisPreview
        ]);
    }

    public function despacharSolicitacao(Request $request)
    {
        $itens = ItemSolicitacao::where('solicitacao_id', '=', $request->id)->where('quantidade_aprovada', '!=', NULL)->get();
        $materiaisID = array_column($itens->toArray(), 'material_id');
        $quantAprovadas = array_column($itens->toArray(), 'quantidade_aprovada');

        for ($i = 0; $i < count($materiaisID); $i++) {
            DB::update('update estoques set quantidade = quantidade - ? where material_id = ?', [$quantAprovadas[$i], $materiaisID[$i]]);
        }

        DB::update(
            'update historico_statuses set status = ?, data_finalizado = ? where solicitacao_id = ?',
            ['Entregue', date('Y-m-d H:i:s'), $request->id]
        );
    }

    public function cancelarSolicitacao(Request $request)
    {
        DB::update(
            'update historico_statuses set status = ?, data_finalizado = ? where solicitacao_id = ?',
            ['Cancelado', date('Y-m-d H:i:s'), $request->id]
        );
    }

    public function getItemSolicitacao($id)
    {
        $consulta = DB::select('select item.quantidade_solicitada, item.quantidade_aprovada, mat.nome, mat.descricao
            from item_solicitacaos item, materials mat where item.solicitacao_id = ? and mat.id = item.material_id', [$id]);
        return json_encode($consulta);
    }

    public function getItemSolicitacaoAdmin($id)
    {
        if (session()->exists('itemSolicitacoes')) {
            session()->forget('itemSolicitacoes');
        }

        $consulta = DB::select('select item.quantidade_solicitada, item.material_id, mat.nome, mat.descricao, item.quantidade_aprovada, item.id, item.quantidade_solicitada, est.quantidade
            from item_solicitacaos item, materials mat, estoques est where item.solicitacao_id = ? and mat.id = item.material_id and est.material_id = item.material_id', [$id]);

        session(['itemSolicitacoes' => $consulta]);

        return json_encode($consulta);
    }

    public function getObservacaoSolicitacao($id)
    {
        $consulta = DB::select('select observacao_requerente, observacao_admin from solicitacaos where id = ?', [$id]);
        return json_encode($consulta);
    }

    function getMateriaisPreview($solicitacoes_id)
    {
        $materiaisIDItem = ItemSolicitacao::select('material_id', 'solicitacao_id')->whereIn('solicitacao_id', $solicitacoes_id)->orderBy('solicitacao_id', 'desc')->get();
        $itensSolicitacaoID =  array_values(array_unique(array_column($materiaisIDItem->toArray(), 'solicitacao_id')));

        $materiais = DB::select('select item.material_id, item.solicitacao_id, mat.nome 
            from item_solicitacaos item, materials mat
            where item.solicitacao_id in (' . implode(',', $solicitacoes_id) . ') and item.material_id = mat.id');

        $materiaisPreview = [];
        $auxCountMaterial = 0;

        for ($i = 0; $i < count($itensSolicitacaoID); $i++) {
            for ($b = 0; $b < count($materiais); $b++) {
                if ($auxCountMaterial > 2) {
                    break;
                }
                if ($itensSolicitacaoID[$i] == $materiais[$b]->solicitacao_id) {
                    if ($auxCountMaterial > 0) {
                        $materiaisPreview[$i] .= ', ' . $materiais[$b]->nome;
                    } else {
                        array_push($materiaisPreview, $materiais[$b]->nome);
                    }
                    $auxCountMaterial++;
                }
            }
            $auxCountMaterial = 0;
        }
        return $materiaisPreview;
    }
}
