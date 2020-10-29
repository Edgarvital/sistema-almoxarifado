@extends('templates.principal')

@section('title') Editar Material @endsection

@section('content')
    <h2>EDITAR MATERIAL</h2>
    <form method="POST" action="{{ route('material.update', ['material' => $material->id]) }}">

        @csrf
        @method('PUT')

        <div class="form-row">
          <div class="form-group col-md-3">
            <label for="inputMaterial">Material</label>
            <input type="text" class="form-control" id="inputMaterial" name="nome" placeholder="Material" value="{{ old('nome', $material->nome) }}">
          </div>
          <div class="form-group col-md-2">
            <label for="inputCodigo">Código</label>
            <input type="text" class="form-control" id="inputCodigo" name="codigo" placeholder="Código" value="{{ old('codigo', $material->codigo) }}">
          </div>
          <div class="form-group col-md-2">
            <label for="inputQuantidadeMin">Quantidade mínima</label>
            <input type="number" class="form-control" id="inputQuantidadeMin" name="quantidade_minima" value="{{ old('quantidade_minima', $material->quantidade_minima) }}">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label for="inputDescricao">Descrição</label>
            <textarea class="form-control" name="descricao" id="inputDescricao" cols="30" rows="3" value="{{ old('descricao', $material->descricao) }}"></textarea>
          </div>
        </div>

        @if($errors->any())
            <div>
                <ul>
                    @foreach($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <button type="submit" class="btn btn-success">Atualizar</button>

    </form>
    <form method="POST" action="{{ route('material.destroy', ['material' => $material->id]) }}">

        @csrf
        @method('DELETE')
        <button class="btn btn-danger" type="submit">EXCLUIR</button>
    </form>
@endsection