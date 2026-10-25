<?php

/**
 * Teste de integração dos models contra um MySQL/MariaDB de TESTE.
 *
 *   php tests/integracao.php
 *
 * ATENÇÃO: apaga todos os dados do banco configurado no .env. Use um banco só de testes.
 */

require_once dirname(__DIR__) . '/model/Init.php';

use Model\Arenas;
use Model\Grupos;
use Model\Inscricoes;
use Model\Jogadores;
use Model\Jogos;
use Model\Login;
use Model\Pagamentos;
use Model\Perfil;
use Model\Partidas;
use Model\Presencas;
use Model\PixPayload;
use Model\Recorrentes;
use Model\Sorteio;
use Model\Times;

if (!in_array('--forcar', $argv, true) && stripos(BANCO, 'teste') === false) {
    exit("Recusado: o banco '" . BANCO . "' não parece ser de teste (o nome deve conter 'teste'). Use --forcar para ignorar.\n");
}

$ok = 0;
$falhas = 0;

function confere($descricao, $condicao)
{
    global $ok, $falhas;
    if ($condicao) {
        $ok++;
        echo "  ok   $descricao\n";
    } else {
        $falhas++;
        echo "  FALHOU  $descricao\n";
    }
}

function erro(callable $fn)
{
    try {
        $fn();
    } catch (\DomainException $e) {
        return $e->getMessage();
    }

    return null;
}

$pdo = new PDO('mysql:host=' . SERVER . ';port=' . PORTA . ';dbname=' . BANCO . ';charset=utf8mb4', USER, PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['jogos_gols', 'jogos_partidas_jogadores', 'jogos_fila', 'jogos_partidas', 'jogos_pagamentos', 'jogos_times_jogadores', 'jogos_times', 'jogos_presencas', 'jogos', 'grupos_jogadores', 'jogadores', 'grupos_admins', 'grupos', 'arenas', 'usuarios'] as $t) {
    $pdo->exec("TRUNCATE TABLE $t");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ------------------------------------------------------------------ Pix / sorteio
echo "Pix\n";
confere('CRC16 padrão ("123456789" = 29B1)', PixPayload::crc16('123456789') === '29B1');
$pix = PixPayload::gerar('+5562999998888', 'João da Silva Sauro Júnior Terceiro', 'Goiânia', 25.5);
confere('começa com 000201', strpos($pix, '000201') === 0);
confere('contém a chave', strpos($pix, '0114+5562999998888') !== false);
confere('valor 25.50', strpos($pix, '540525.50') !== false);
confere('nome sem acento e <=25', strpos($pix, '5925JOAO DA SILVA SAURO JUNIO') !== false);
confere('cidade sem acento', strpos($pix, '6007GOIANIA') !== false);
confere('CRC válido', substr($pix, -4) === PixPayload::crc16(substr($pix, 0, -4)));

echo "Sorteio\n";
$scores = [1 => 9, 2 => 8, 3 => 7, 4 => 6, 5 => 5, 6 => 5, 7 => 4, 8 => 3, 9 => 2, 10 => 1];
$times = (new Sorteio())->balancear($scores, 2, 42);
confere('2 times de 5', count($times) === 2 && count($times[0]) === 5 && count($times[1]) === 5);
confere('diferença mínima (0)', Sorteio::diferenca($times, $scores) < 0.001);
$ts = (new Sorteio())->balancear($scores + [11 => 6.5], 3, 7);
confere('11 jogadores em 3 times (4/4/3)', count($ts) === 3 && count(array_merge(...$ts)) === 11);
$variados = [];
for ($i = 0; $i < 30; $i++) {
    $variados[json_encode(array_map(function ($t) { sort($t); return $t; }, (new Sorteio())->balancear($scores, 2)))] = 1;
}
confere('resultados variam entre sorteios', count($variados) > 3);

// ------------------------------------------------------------------ login
echo "Login\n";
$login = new Login();
$u1 = $login->cadastrar(['nome' => 'Admin Um', 'email' => 'um@teste.com', 'telefone' => '(62) 91111-1111', 'senha' => 'senha1234']);
$u2 = $login->cadastrar(['nome' => 'Admin Dois', 'email' => 'dois@teste.com', 'telefone' => '62 92222-2222', 'senha' => 'senha1234']);
confere('cadastra e devolve id', $u1['id'] > 0 && $u2['id'] > $u1['id']);
confere('e-mail duplicado bloqueado', erro(function () use ($login) { $login->cadastrar(['nome' => 'X', 'email' => 'UM@teste.com', 'telefone' => '62933333333', 'senha' => 'senha1234']); }) !== null);
confere('WhatsApp duplicado bloqueado', erro(function () use ($login) { $login->cadastrar(['nome' => 'X', 'email' => 'x@teste.com', 'telefone' => '5562911111111', 'senha' => 'senha1234']); }) !== null);
confere('cadastro sem WhatsApp recusado', erro(function () use ($login) { $login->cadastrar(['nome' => 'X', 'email' => 'x@teste.com', 'senha' => 'senha1234']); }) !== null);
confere('senha curta bloqueada', erro(function () use ($login) { $login->cadastrar(['nome' => 'X', 'email' => 'x@teste.com', 'telefone' => '62933333333', 'senha' => '123']); }) !== null);
confere('login correto', $login->autenticar(['email' => 'um@teste.com', 'senha' => 'senha1234'])['id'] === $u1['id']);
confere('senha errada recusada', erro(function () use ($login) { $login->autenticar(['email' => 'um@teste.com', 'senha' => 'errada']); }) !== null);
confere('sem senha em texto na sessão', !array_key_exists('senha', $u1));

// ------------------------------------------------------------------ arenas
echo "Arenas\n";
$arenas1 = new Arenas($u1);
$arenas2 = new Arenas($u2);
$idArena = $arenas1->salvar(['nome' => 'Arena Society', 'valor' => '200,00', 'endereco' => 'Rua 1, 100', 'url_mapa' => 'https://maps.google.com/?q=1']);
confere('cria arena', $idArena > 0);
confere('outro admin vê a arena (pública)', count($arenas2->listing()) === 1 && $arenas2->listing()[0]['minha'] === false);
confere('outro admin NÃO edita', erro(function () use ($arenas2, $idArena) { $arenas2->salvar(['id' => $idArena, 'nome' => 'Hack', 'endereco' => 'x']); }) !== null);
confere('outro admin NÃO exclui', erro(function () use ($arenas2, $idArena) { $arenas2->excluir($idArena); }) !== null);
confere('link de mapa inválido recusado', erro(function () use ($arenas1) { $arenas1->salvar(['nome' => 'A', 'endereco' => 'B', 'url_mapa' => 'javascript:alert(1)']); }) !== null);
confere('valor lido como 200', $arenas1->listing()[0]['valor'] === 200.0);

// ------------------------------------------------------------------ grupos e jogadores
echo "Grupos\n";
$g1 = new Grupos($u1);
$g2 = new Grupos($u2);
$idGrupo = $g1->salvar([
    'nome' => 'Futebol de sexta', 'jogadores_por_time' => 5, 'recorrente' => 's', 'dia_semana' => 5, 'horario' => '20:00',
    'id_arena_padrao' => $idArena, 'pix_tipo' => 'celular', 'pix_chave' => '(62) 99999-8888', 'pix_nome' => 'Admin Um', 'pix_cidade' => 'Goiania',
]);
$grupo = $g1->buscar($idGrupo);
confere('chave de celular guardada com +55', $grupo['pix_chave'] === '+5562999998888');
confere('resumo do jogo fixo', $grupo['resumo_fixo'] === 'Toda sexta, 20:00');
confere('grupo novo nasce com os dois coletes padrão', $grupo['coletes'][0]['nome'] === 'Laranja' && $grupo['coletes'][1]['cor'] === '#007AFF');
$dadosGrupo = [
    'id' => $idGrupo, 'nome' => 'Futebol de sexta', 'jogadores_por_time' => 5, 'recorrente' => 's', 'dia_semana' => 5, 'horario' => '20:00',
    'id_arena_padrao' => $idArena, 'pix_tipo' => 'celular', 'pix_chave' => '(62) 99999-8888', 'pix_nome' => 'Admin Um', 'pix_cidade' => 'Goiania',
];
$g1->salvar($dadosGrupo);
confere('salvar sem informar coletes mantém os que já existem', $g1->buscar($idGrupo)['coletes'][0]['nome'] === 'Laranja');
$g1->salvar($dadosGrupo + ['colete1_nome' => 'Amarelo', 'colete1_cor' => '#ffcc00', 'colete2_nome' => 'Preto', 'colete2_cor' => '#1C1C1E']);
$grupo = $g1->buscar($idGrupo);
confere('coletes do grupo salvos (cor em maiúsculas)', $grupo['coletes'][0] === ['nome' => 'Amarelo', 'cor' => '#FFCC00'] && $grupo['coletes'][1]['nome'] === 'Preto');
confere('coletes com a mesma cor recusados', erro(function () use ($g1, $dadosGrupo) { $g1->salvar($dadosGrupo + ['colete1_nome' => 'A', 'colete1_cor' => '#FF0000', 'colete2_nome' => 'B', 'colete2_cor' => '#ff0000']); }) !== null);
confere('cor de colete inválida recusada', erro(function () use ($g1, $dadosGrupo) { $g1->salvar($dadosGrupo + ['colete1_nome' => 'A', 'colete1_cor' => 'vermelho', 'colete2_nome' => 'B', 'colete2_cor' => '#00FF00']); }) !== null);
confere('outro admin não vê o grupo', erro(function () use ($g2, $idGrupo) { $g2->buscar($idGrupo); }) !== null);
confere('listagem do outro admin vazia', count($g2->listing()) === 0);

echo "Vários administradores\n";
$g1->salvar($dadosGrupo + ['admins' => '(62) 92222-2222, 62 94444-4444, 62 91111-1111']);
$grupo = $g1->buscar($idGrupo);
confere('dono vê a lista: ele + 2 convidados (o próprio número é ignorado)', count($grupo['admins']) === 3 && $grupo['admins'][0]['dono'] && $grupo['dono'] === true);
confere('convidado com conta aparece com o nome', $grupo['admins'][1]['nome'] === 'Admin Dois' && $grupo['admins'][2]['nome'] === null);
confere('convidado vê o grupo na listagem', count($g2->listing()) === 1);
confere('convidado abre o grupo, mas não é dono', $g2->buscar($idGrupo)['dono'] === false);
$g2->salvar(array_merge($dadosGrupo, ['nome' => 'Futebol de sexta!', 'admins' => '']));
confere('convidado edita o grupo, sem mexer nos administradores', $g1->buscar($idGrupo)['nome'] === 'Futebol de sexta!' && count($g1->buscar($idGrupo)['admins']) === 3);
confere('convidado não exclui o grupo', erro(function () use ($g2, $idGrupo) { $g2->excluir($idGrupo); }) !== null);
confere('WhatsApp de admin inválido recusado', erro(function () use ($g1, $dadosGrupo) { $g1->salvar($dadosGrupo + ['admins' => '123']); }) !== null);
$g1->salvar($dadosGrupo);
confere('salvar sem o campo admins mantém a lista', count($g1->buscar($idGrupo)['admins']) === 3);
$g1->salvar($dadosGrupo + ['admins' => '']);
confere('dono tira os convidados', count($g1->buscar($idGrupo)['admins']) === 1);
confere('ex-convidado perde o acesso', count($g2->listing()) === 0 && erro(function () use ($g2, $idGrupo) { $g2->buscar($idGrupo); }) !== null);
confere('pix incompleto recusado', erro(function () use ($g1) { $g1->salvar(['nome' => 'X', 'jogadores_por_time' => 5, 'pix_chave' => 'abc']); }) !== null);

echo "Jogadores\n";
$j1 = new Jogadores($u1);
$j2 = new Jogadores($u2);
$nomes = ['Ana', 'Bruno', 'Caio', 'Davi', 'Edu', 'Fabio', 'Gui', 'Hugo', 'Igor', 'Joao', 'Lucas'];
$idsJog = [];
foreach ($nomes as $i => $nome) {
    $idsJog[$nome] = $j1->salvar([
        'id_grupo' => $idGrupo, 'nome' => $nome, 'whatsapp' => '6299' . str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT) . '88',
        'score' => (string) (10 - $i * 0.8), 'instagram' => '@' . strtolower($nome),
    ]);
}
$lista = $j1->listing($idGrupo);
confere('11 jogadores no grupo', count($lista) === 11);
confere('whatsapp normalizado com 55', strncmp($lista[0]['whatsapp'], '55', 2) === 0);
confere('score em passos de 0,5', fmod($lista[0]['score'] * 2, 1) == 0);
confere('whatsapp duplicado no grupo bloqueado', erro(function () use ($j1, $idGrupo, $lista) { $j1->salvar(['id_grupo' => $idGrupo, 'nome' => 'Dup', 'whatsapp' => $lista[0]['whatsapp']]); }) !== null);
confere('outro admin não lista jogadores do grupo', erro(function () use ($j2, $idGrupo) { $j2->listing($idGrupo); }) !== null);

$idGrupo2 = $g2->salvar(['nome' => 'Outro grupo', 'jogadores_por_time' => 5]);
$idCompart = $j2->salvar(['id_grupo' => $idGrupo2, 'nome' => 'Nome Diferente', 'whatsapp' => $lista[0]['whatsapp'], 'score' => 3]);
confere('mesma pessoa em dois grupos (mesmo id)', $idCompart === $lista[0]['id']);
$j2->salvar(['id_grupo' => $idGrupo2, 'id' => $idCompart, 'nome' => 'Sobrescrita', 'whatsapp' => $lista[0]['whatsapp'], 'score' => 9]);
$nomeDepois = $pdo->query('SELECT nome FROM jogadores WHERE id = ' . $idCompart)->fetchColumn();
confere('admin 2 não sobrescreve dados pessoais compartilhados', $nomeDepois !== 'Sobrescrita' && $nomeDepois !== 'Nome Diferente');
$score1 = $pdo->query("SELECT score FROM grupos_jogadores WHERE id_grupo = $idGrupo AND id_jogador = $idCompart")->fetchColumn();
confere('score é independente por grupo', (float) $score1 !== 9.0);

// ------------------------------------------------------------------ jogos
echo "Jogos fixos\n";
$rec = new Recorrentes($u1);
$criados = $rec->gerar(4);
confere('gera ~4 jogos de sexta', $criados >= 3 && $criados <= 5);
confere('não duplica ao gerar de novo', $rec->gerar(4) === 0);
confere('grupo do outro admin não gerou nada', (new Recorrentes($u2))->gerar(4) === 0);

$jogos = new Jogos($u1);
$ag = $jogos->listing($idGrupo);
confere('listagem traz próximos', count($ag['proximos']) === $criados);
$idJogo = $ag['proximos'][0]['id'];
$jogos2 = new Jogos($u2);
confere('outro admin não abre o jogo', erro(function () use ($jogos2, $idJogo) { $jogos2->detalhe($idJogo); }) !== null);
confere('jogo fixo não pode ser excluído', erro(function () use ($jogos, $idJogo) { $jogos->excluir($idJogo); }) !== null);

$d = $jogos->detalhe($idJogo);
confere('detalhe: arena padrão e 5 por time', $d['arena']['nome'] === 'Arena Society' && $d['jogadores_por_time'] === 5);
confere('detalhe: 11 pendentes', count($d['pendentes']) === 11);
confere('link público com token', preg_match('/t=[0-9a-f]{32}$/', $d['link_publico']) === 1);
$token = substr($d['link_publico'], -32);

echo "Presenças\n";
$pub = new Presencas();
$tel = $lista[1]['whatsapp'];
$r = $pub->publicoResponder($token, $tel, '', 'confirmado');
confere('confirma pelo link com número cadastrado', $r['status'] === 'ok' && $r['nome'] === $lista[1]['nome']);
$r = $pub->publicoResponder($token, '(62) 98888-0000', '', 'confirmado');
confere('número novo pede nome', $r['status'] === 'precisa_nome');
$r = $pub->publicoResponder($token, '(62) 98888-0000', 'Convidado Novo', 'confirmado');
confere('número novo com nome é cadastrado', $r['status'] === 'ok');
confere('token inválido recusado', erro(function () use ($pub) { $pub->publicoInfo('zzz'); }) !== null);
confere('público não expõe whatsapp', strpos(json_encode($pub->publicoInfo($token)), $tel) === false);

$pres = new Presencas($u1);
foreach ($nomes as $nome) {
    $pres->responder($idJogo, $idsJog[$nome], 'confirmado');
}
$pres->responder($idJogo, $idsJog['Lucas'], 'recusado');
$d = $jogos->detalhe($idJogo);
confere('10 antigos + 1 convidado confirmados', count($d['confirmados']) === 11 && count($d['recusados']) === 1);
confere('outro admin não mexe na presença', erro(function () use ($u2, $idJogo, $idsJog) { (new Presencas($u2))->responder($idJogo, $idsJog['Ana'], 'confirmado'); }) !== null);

echo "Chegada e sorteio\n";
confere('sortear sem chegadas é recusado', erro(function () use ($u1, $idJogo) { (new Times($u1))->sortear($idJogo); }) !== null);
$ordemChegada = ['Hugo', 'Igor', 'Ana', 'Joao', 'Bruno', 'Caio', 'Davi', 'Edu', 'Fabio', 'Gui'];
foreach ($ordemChegada as $nome) {
    $pres->chegada($idJogo, $idsJog[$nome], true);
    usleep(3000);
}
$d = $jogos->detalhe($idJogo);
$ordemLida = [];
foreach ($d['confirmados'] as $c) {
    if ($c['ordem_chegada']) {
        $ordemLida[$c['ordem_chegada']] = $c['nome'];
    }
}
ksort($ordemLida);
confere('ordem de chegada preservada', array_values($ordemLida) === $ordemChegada);

$timesSvc = new Times($u1);
$qtd = $timesSvc->sortear($idJogo);
$d = $jogos->detalhe($idJogo);
confere('10 chegaram => 2 times e ninguém na lista', $qtd === 0 && count($d['times']) === 2 && count($d['fila']) === 0);
confere('5 em cada', count($d['times'][0]['jogadores']) === 5 && count($d['times'][1]['jogadores']) === 5);
confere('times equilibrados (<= 1.0)', $d['diferenca'] <= 1.0);
confere('jogo passou a em_andamento', $d['situacao'] === 'em_andamento');
confere('primeiro time veste o colete 1 e o segundo o 2', $d['times'][0]['colete'] === 1 && $d['times'][1]['colete'] === 2);
confere('cores dos times vêm dos coletes do grupo', $d['times'][0]['cor'] === '#FFCC00' && $d['times'][1]['cor'] === '#1C1C1E');

// Mais dois chegam antes de sortear: o sorteio por score vale só para os 10 primeiros, os outros vão para a lista.
$pres->chegada($idJogo, $idsJog['Lucas'], true); // tinha recusado, mas apareceu
usleep(3000);
$idConvidado = (int) $pdo->query("SELECT id FROM jogadores WHERE nome = 'Convidado Novo'")->fetchColumn();
$pres->chegada($idJogo, $idConvidado, true);
$espera = $timesSvc->sortear($idJogo);
$d = $jogos->detalhe($idJogo);
confere('12 chegaram: 2 times de 5 e 2 na lista de espera', count($d['times']) === 2 && $espera === 2 && count($d['fila']) === 2);
confere('lista de espera na ordem de chegada (Lucas e depois o Convidado)', $d['fila'][0]['id_jogador'] === $idsJog['Lucas'] && $d['fila'][1]['id_jogador'] === $idConvidado && $d['fila'][0]['posicao'] === 1 && $d['fila'][1]['posicao'] === 2);
$idsPrimeiros = [];
foreach ($d['times'] as $t) {
    foreach ($t['jogadores'] as $jg) {
        $idsPrimeiros[] = $jg['id_jogador'];
    }
}
$esperados = array_map(function ($n) use ($idsJog) { return $idsJog[$n]; }, $ordemChegada);
sort($idsPrimeiros);
sort($esperados);
confere('dois times = 10 primeiros a chegar', $idsPrimeiros === $esperados);
confere('quem está na lista não aparece como "sem time"', count($d['sem_time']) === 0);

// posição de cada jogador na ordem de chegada (a "lista")
$posChegada = array_flip(array_map(function ($n) use ($idsJog) { return $idsJog[$n]; }, $ordemChegada));
$porChegada = function (array $ids) use ($posChegada) {
    usort($ids, function ($x, $y) use ($posChegada) { return $posChegada[$x] <=> $posChegada[$y]; });
    return $ids;
};

echo "Partidas, lista de espera e coletes\n";
$part = new Partidas($u1);
confere('dois times com os coletes 1 e 2', $d['times'][0]['colete'] === 1 && $d['times'][1]['colete'] === 2);
confere('dentro do time os jogadores seguem a ordem de chegada', array_column($d['times'][0]['jogadores'], 'id_jogador') === $porChegada(array_column($d['times'][0]['jogadores'], 'id_jogador')));
confere('antes de começar: primeira partida planejada', $d['proxima']['primeira'] === true && $d['partida_atual'] === null);
confere('outro admin não inicia partida', erro(function () use ($u2, $idJogo) { (new Partidas($u2))->iniciar($idJogo); }) !== null);

$p1 = $part->iniciar($idJogo);
$d = $jogos->detalhe($idJogo);
$pa = $d['partida_atual'];
confere('partida 1 em andamento com 5 de cada lado', $pa['numero'] === 1 && count($pa['a']['jogadores']) === 5 && count($pa['b']['jogadores']) === 5);
confere('lado a = colete 1 (Amarelo), lado b = colete 2 (Preto)', $pa['a']['colete_nome'] === 'Amarelo' && $pa['b']['colete_nome'] === 'Preto');
confere('não inicia duas partidas ao mesmo tempo', erro(function () use ($part, $idJogo) { $part->iniciar($idJogo); }) !== null);
confere('não sorteia de novo com partida criada', erro(function () use ($timesSvc, $idJogo) { $timesSvc->sortear($idJogo); }) !== null);
confere('não fecha o jogo com partida em andamento', erro(function () use ($u1, $idJogo) { (new Pagamentos($u1))->finalizar($idJogo, 100); }) !== null);
confere('não tira jogador da quadra durante a partida', erro(function () use ($u1, $idJogo, $pa) { (new Times($u1))->moverNaFila($idJogo, $pa['a']['jogadores'][0]['id_jogador'], 'fila'); }) !== null);
confere('quem está na quadra não é movido dentro da lista', erro(function () use ($u1, $idJogo, $pa) { (new Times($u1))->moverNaFila($idJogo, $pa['a']['jogadores'][0]['id_jogador'], 'subir'); }) !== null);

$a0 = $pa['a']['jogadores'][0]['id_jogador'];
$a1 = $pa['a']['jogadores'][1]['id_jogador'];
$b0 = $pa['b']['jogadores'][0]['id_jogador'];
$b1 = $pa['b']['jogadores'][1]['id_jogador'];
$part->gol($idJogo, $p1, $a0, '');
$part->gol($idJogo, $p1, $a0, '');
$part->gol($idJogo, $p1, $b0, '', true);        // gol contra de quem é do lado b: vale para o lado a
$part->gol($idJogo, $p1, 0, 'b');               // não sei quem fez
$part->gol($idJogo, $p1, $b1, '');
$d = $jogos->detalhe($idJogo);
confere('placar 3 x 2 (gol contra vale para o adversário)', $d['partida_atual']['a']['gols'] === 3 && $d['partida_atual']['b']['gols'] === 2);
$ultimoGol = end($d['partida_atual']['gols']);
$part->desfazerGol($idJogo, $ultimoGol['id']);
$d = $jogos->detalhe($idJogo);
confere('desfazer gol tira do placar (3 x 1)', $d['partida_atual']['b']['gols'] === 1 && count($d['partida_atual']['gols']) === 4);
$part->gol($idJogo, $p1, $b1, '');
confere('gol de jogador que não está na partida é recusado', erro(function () use ($part, $idJogo, $p1, $idsJog) { $part->gol($idJogo, $p1, 999999, ''); }) !== null);
confere('gol contra sem jogador é recusado', erro(function () use ($part, $idJogo, $p1) { $part->gol($idJogo, $p1, 0, 'a', true); }) !== null);
confere('outro admin não marca gol', erro(function () use ($u2, $idJogo, $p1, $a0) { (new Partidas($u2))->gol($idJogo, $p1, $a0, ''); }) !== null);
confere('artilheiro da noite: jogador a0 com 2 gols', $jogos->detalhe($idJogo)['artilheiros'][0]['id_jogador'] === $a0 && $jogos->detalhe($idJogo)['artilheiros'][0]['gols'] === 2);

// a lista pode ser reorganizada durante a partida
$timesSvc->moverNaFila($idJogo, $idConvidado, 'inicio');
$d = $jogos->detalhe($idJogo);
confere('"primeiro da fila" muda a ordem mesmo com partida em andamento', $d['fila'][0]['id_jogador'] === $idConvidado && $d['fila'][1]['id_jogador'] === $idsJog['Lucas']);
$timesSvc->moverNaFila($idJogo, $idConvidado, 'descer');
$d = $jogos->detalhe($idJogo);
confere('descer na fila volta à ordem original', $d['fila'][0]['id_jogador'] === $idsJog['Lucas'] && $d['fila'][1]['id_jogador'] === $idConvidado);

$idTime1 = $pa['a']['id_time'];
$idTime2 = $pa['b']['id_time'];
$time2Ids = $porChegada(array_column($pa['b']['jogadores'], 'id_jogador'));
$time1Ids = $porChegada(array_column($pa['a']['jogadores'], 'id_jogador'));

$res = $part->encerrar($idJogo, $p1);
confere('encerrar devolve o resultado', $res['empate'] === false && $res['gols_a'] === 3 && $res['gols_b'] === 2 && $res['saiu'] === 'Time 2' && $res['colete_fica'] === 'Amarelo');
$d = $jogos->detalhe($idJogo);
confere('partida 1 encerrada 3 x 2, lado a venceu', $d['partida_atual'] === null && $d['historico'][0]['a']['gols'] === 3 && $d['historico'][0]['b']['gols'] === 2 && $d['historico'][0]['lado_vencedor'] === 'a');
confere('vencedor segue na quadra com o colete 1; o time que perdeu desfez-se', count($d['times']) === 1 && $d['times'][0]['id'] === $idTime1 && $d['times'][0]['colete'] === 1);
confere('lista: quem estava de fora primeiro, depois os 5 que perderam na ordem de chegada',
    array_column($d['fila'], 'id_jogador') === array_merge([$idsJog['Lucas'], $idConvidado], $time2Ids));
confere('quem perdeu sabe em que partida saiu', $d['fila'][2]['saiu_partida'] === 1 && $d['fila'][0]['saiu_partida'] === null);
confere('encerrar duas vezes é recusado', erro(function () use ($part, $idJogo, $p1) { $part->encerrar($idJogo, $p1); }) !== null);
confere('não marca gol em partida encerrada', erro(function () use ($part, $idJogo, $p1, $a0) { $part->gol($idJogo, $p1, $a0, ''); }) !== null);

$pr = $d['proxima'];
confere('próxima: entram os 5 primeiros da lista com o colete de quem saiu (2)',
    $pr['primeira'] === false && $pr['colete_entra'] === 2 && $pr['entra']['colete_nome'] === 'Preto'
    && array_column($pr['entra']['jogadores'], 'id_jogador') === array_merge([$idsJog['Lucas'], $idConvidado], array_slice($time2Ids, 0, 3)));
confere('2 de fora e 3 do time que perdeu; nenhum jogador sobra completando', $pr['de_fora'] === 2 && $pr['do_saiu'] === 3 && $pr['faltam'] === 0 && $pr['revanche'] === false);
confere('quem continua esperando: os 2 últimos do time que perdeu', array_column($pr['fila_restante'], 'id_jogador') === array_slice($time2Ids, 3));
confere('o plano não oferece escolha de empréstimo (a lista manda)', !isset($pr['emprestaveis']) && !isset($pr['sugestao']));

$p2 = $part->iniciar($idJogo);
$d = $jogos->detalhe($idJogo);
$pa = $d['partida_atual'];
$qtdEmp = 0;
foreach ($pa['b']['jogadores'] as $jg) {
    $qtdEmp += $jg['emprestado'] ? 1 : 0;
}
$idTime3 = $pa['b']['id_time'];
confere('partida 2: novo time no colete 2 (5 jogadores, 3 vindos do time que perdeu)', $pa['numero'] === 2 && $idTime3 !== $idTime2 && count($pa['b']['jogadores']) === 5 && $qtdEmp === 3 && $pa['a']['id_time'] === $idTime1 && $pa['b']['entrou'] === true && $pa['a']['entrou'] === false);
confere('lista agora só tem os 2 que continuam esperando', array_column($d['fila'], 'id_jogador') === array_slice($time2Ids, 3));
$timesPorId = [];
foreach ($d['times'] as $t) {
    $timesPorId[$t['id']] = $t;
}
confere('o time que entrou veste o colete 2', $timesPorId[$idTime3]['colete'] === 2 && $timesPorId[$idTime3]['cor'] === '#1C1C1E');

// descartar volta tudo como estava
$part->descartar($idJogo, $p2);
$d = $jogos->detalhe($idJogo);
confere('descartar devolve os jogadores ao começo da lista, na mesma ordem',
    array_column($d['fila'], 'id_jogador') === array_merge([$idsJog['Lucas'], $idConvidado], $time2Ids) && count($d['times']) === 1 && $d['partida_atual'] === null);
confere('descartar mantém quem perdeu marcado como tal (o plano é o mesmo)', $d['proxima']['de_fora'] === 2 && $d['proxima']['do_saiu'] === 3);
confere('partida descartada não entra no histórico', count($d['historico']) === 1);

$p2 = $part->iniciar($idJogo);
$d = $jogos->detalhe($idJogo);
confere('numeração segue (descartada não conta): partida 2', $d['partida_atual']['numero'] === 2);
$idTime3 = $d['partida_atual']['b']['id_time']; // o time é formado de novo ao iniciar

// empate: é obrigatório decidir quem sai
$part->gol($idJogo, $p2, $d['partida_atual']['a']['jogadores'][0]['id_jogador'], '');
$part->gol($idJogo, $p2, $d['partida_atual']['b']['jogadores'][0]['id_jogador'], '');
confere('empate sem decidir quem sai é recusado', erro(function () use ($part, $idJogo, $p2) { $part->encerrar($idJogo, $p2); }) !== null);
confere('escolher time que não joga a partida é recusado', erro(function () use ($part, $idJogo, $p2, $idTime2) { $part->encerrar($idJogo, $p2, $idTime2); }) !== null);
confere('lado do par inválido é recusado', erro(function () use ($part, $idJogo, $p2) { $part->encerrar($idJogo, $p2, 0, 'x'); }) !== null);
confere('empate: por padrão sai quem está há mais partidas na quadra (time 1)', $d['partida_atual']['sai_padrao'] === 'a' && $d['partida_atual']['a']['sequencia'] === 1 && $d['partida_atual']['b']['sequencia'] === 0);
$res = $part->encerrar($idJogo, $p2, $idTime3);
$d = $jogos->detalhe($idJogo);
confere('empate 1 x 1 com escolha manual: sai o time escolhido (3)', $d['historico'][0]['lado_vencedor'] === null && $d['historico'][0]['id_time_saiu'] === $idTime3 && $d['historico'][0]['desempate']['tipo'] === 'manual' && $res['empate'] === true);
confere('time 1 continua com o colete 1', $d['times'][0]['id'] === $idTime1 && $d['times'][0]['colete'] === 1);
confere('o time que saiu foi para o fim da lista, na ordem de chegada (os 2 que esperavam vêm antes)',
    array_slice(array_column($d['fila'], 'id_jogador'), 0, 2) === array_slice($time2Ids, 3) && count($d['fila']) === 7);

// reabrir o encerramento por engano
confere('só a última partida pode ser reaberta', erro(function () use ($part, $idJogo, $p1) { $part->reabrir($idJogo, $p1); }) !== null);
$part->reabrir($idJogo, $p2);
$d = $jogos->detalhe($idJogo);
confere('reabrir devolve a partida e o colete ao time', $d['partida_atual']['id'] === $p2 && $d['partida_atual']['a']['gols'] === 1 && $d['partida_atual']['b']['gols'] === 1);
confere('reabrir tira o time da lista e apaga a decisão do empate', count($d['fila']) === 2 && array_column($d['fila'], 'id_jogador') === array_slice($time2Ids, 3));
$timesPorId = [];
foreach ($d['times'] as $t) {
    $timesPorId[$t['id']] = $t;
}
confere('time 3 voltou à quadra com colete 2 e os mesmos 5 jogadores', $timesPorId[$idTime3]['colete'] === 2 && count($timesPorId[$idTime3]['jogadores']) === 5);
$part->gol($idJogo, $p2, $d['partida_atual']['b']['jogadores'][1]['id_jogador'], '');
$part->encerrar($idJogo, $p2);
$d = $jogos->detalhe($idJogo);
confere('depois de reabrir: lado b venceu 2 x 1 e o time 1 sai', $d['historico'][0]['lado_vencedor'] === 'b' && $d['historico'][0]['id_time_saiu'] === $idTime1 && $d['historico'][0]['desempate'] === null);
confere('time 3 (vencedor) segue com o colete 2; o colete 1 está livre', count($d['times']) === 1 && $d['times'][0]['id'] === $idTime3 && $d['proxima']['colete_entra'] === 1);
confere('lista: os 2 que esperavam e depois o time 1 na ordem de chegada',
    array_column($d['fila'], 'id_jogador') === array_merge(array_slice($time2Ids, 3), $time1Ids));
confere('próxima: 2 de fora + 3 do time que acabou de sair, colete 1 (Amarelo)',
    array_column($d['proxima']['entra']['jogadores'], 'id_jogador') === array_merge(array_slice($time2Ids, 3), array_slice($time1Ids, 0, 3))
    && $d['proxima']['de_fora'] === 2 && $d['proxima']['do_saiu'] === 3 && $d['proxima']['entra']['colete_nome'] === 'Amarelo');
$idTime4Esperado = $d['proxima']['entra']['nome'];

// partida 3: empate decidido no par ou ímpar
$p3 = $part->iniciar($idJogo);
$d = $jogos->detalhe($idJogo);
$pa = $d['partida_atual'];
confere('partida 3: o time que entra tem outro nome e o colete 1', $pa['a']['nome'] === $idTime4Esperado && $pa['a']['colete'] === 1 && $pa['b']['id_time'] === $idTime3);
$idTime4 = $pa['a']['id_time'];
$part->gol($idJogo, $p3, $pa['a']['jogadores'][0]['id_jogador'], '');
$part->gol($idJogo, $p3, $pa['b']['jogadores'][0]['id_jogador'], '');
$res = $part->encerrar($idJogo, $p3, 0, 'a');
$ds = $res['desempate'];
confere('par ou ímpar: números de 0 a 5 e soma conferem', $ds['tipo'] === 'par_impar' && $ds['lado_par'] === 'a' && $ds['num_a'] >= 0 && $ds['num_a'] <= 5 && $ds['num_b'] >= 0 && $ds['num_b'] <= 5 && $ds['soma'] === $ds['num_a'] + $ds['num_b']);
confere('par ou ímpar: soma par => ganha o lado do par; ímpar => o outro', $ds['resultado'] === ($ds['soma'] % 2 === 0 ? 'par' : 'impar') && $ds['lado_ganhou'] === ($ds['soma'] % 2 === 0 ? 'a' : 'b'));
$d = $jogos->detalhe($idJogo);
$h3 = $d['historico'][0];
$idSaiu3 = $ds['lado_ganhou'] === 'a' ? $idTime3 : $idTime4;
confere('quem ganha o par ou ímpar fica; o outro time sai', $h3['id_time_saiu'] === $idSaiu3 && $h3['desempate']['tipo'] === 'par_impar' && $h3['desempate']['soma'] === $ds['soma'] && $res['saiu'] !== $res['fica']);
confere('o time que ganhou o par ou ímpar segue com o colete', count($d['times']) === 1 && $d['times'][0]['id'] === ($idSaiu3 === $idTime3 ? $idTime4 : $idTime3));
confere('lista tem 2 que esperavam + 5 de quem saiu', count($d['fila']) === 7);

echo "Jogador atrasado e lista de espera\n";
$idAtrasado = $j1->salvar(['id_grupo' => $idGrupo, 'nome' => 'Atrasado', 'whatsapp' => '62966665555', 'score' => '6']);
$pres->chegada($idJogo, $idAtrasado, true);
$d = $jogos->detalhe($idJogo);
confere('quem chegou depois aparece como "sem time"', count($d['sem_time']) === 1 && $d['sem_time'][0]['id_jogador'] === $idAtrasado);
$posicao = $timesSvc->adicionar($idJogo, $idAtrasado);
$d = $jogos->detalhe($idJogo);
confere('atrasado entra no fim da lista de espera', count($d['sem_time']) === 0 && $posicao === 8 && end($d['fila'])['id_jogador'] === $idAtrasado);
confere('atrasado não entra duas vezes', erro(function () use ($timesSvc, $idJogo, $idAtrasado) { $timesSvc->adicionar($idJogo, $idAtrasado); }) !== null);
confere('quem não chegou não entra na lista', erro(function () use ($timesSvc, $idJogo, $idsJog) { $timesSvc->adicionar($idJogo, $idsJog['Lucas'] + 9999); }) !== null);

$primeiro = $d['fila'][0]['id_jogador'];
$timesSvc->moverNaFila($idJogo, $primeiro, 'fim');
$d = $jogos->detalhe($idJogo);
confere('"último da fila" manda o jogador para o fim', end($d['fila'])['id_jogador'] === $primeiro && $d['fila'][0]['id_jogador'] !== $primeiro);
$timesSvc->moverNaFila($idJogo, $primeiro, 'subir');
$d = $jogos->detalhe($idJogo);
confere('subir uma posição', $d['fila'][count($d['fila']) - 2]['id_jogador'] === $primeiro);
confere('posições seguem 1..n', array_column($d['fila'], 'posicao') === range(1, count($d['fila'])));
confere('quem não está na lista não pode ser movido nela', erro(function () use ($timesSvc, $idJogo, $idsJog) { $timesSvc->moverNaFila($idJogo, $idsJog['Lucas'] + 9999, 'subir'); }) !== null);
confere('movimento inválido é recusado', erro(function () use ($timesSvc, $idJogo, $primeiro) { $timesSvc->moverNaFila($idJogo, $primeiro, 'pular'); }) !== null);
confere('outro admin não mexe na lista', erro(function () use ($u2, $idJogo, $primeiro) { (new Times($u2))->moverNaFila($idJogo, $primeiro, 'inicio'); }) !== null);

$naQuadra = $d['times'][0]['jogadores'][0]['id_jogador'];
$tamanho = count($d['fila']);
$timesSvc->moverNaFila($idJogo, $naQuadra, 'fila');
$d = $jogos->detalhe($idJogo);
confere('entre partidas dá para tirar alguém da quadra: vai para o fim da lista', count($d['fila']) === $tamanho + 1 && end($d['fila'])['id_jogador'] === $naQuadra && count($d['times'][0]['jogadores']) === 4);
$timesSvc->moverNaFila($idJogo, $naQuadra, 'inicio');
$d = $jogos->detalhe($idJogo);
confere('e trazê-lo para o começo da lista', $d['fila'][0]['id_jogador'] === $naQuadra && $d['proxima']['entra']['jogadores'][0]['id_jogador'] === $naQuadra);

echo "Relatório do jogo\n";
$rel = $part->relatorioDoJogo($idJogo);
confere('relatório: 3 partidas encerradas e 10 gols', $rel['totais']['partidas'] === 3 && $rel['totais']['gols'] === 10 && count($rel['partidas']) === 3 && $rel['totais']['em_andamento'] === false);
confere('relatório: partidas em ordem, com o par ou ímpar da última', $rel['partidas'][0]['numero'] === 1 && $rel['partidas'][2]['desempate']['tipo'] === 'par_impar' && $rel['partidas'][1]['desempate'] === null);
confere('relatório: cabeçalho do jogo', $rel['jogo']['grupo_nome'] === 'Pelada de Sexta' || $rel['jogo']['grupo_nome'] !== '');
$somaPartidas = 0;
$linhaRel = [];
foreach ($rel['jogadores'] as $l) {
    $somaPartidas += $l['partidas'];
    $linhaRel[$l['id_jogador']] = $l;
}
confere('relatório por jogador: 30 participações (3 partidas x 10)', $somaPartidas === 30);
confere('relatório por jogador: V+E+D fecha com as partidas e a sequência tem o mesmo tamanho',
    $linhaRel[$a0]['vitorias'] + $linhaRel[$a0]['empates'] + $linhaRel[$a0]['derrotas'] === $linhaRel[$a0]['partidas'] && count($linhaRel[$a0]['sequencia']) === $linhaRel[$a0]['partidas'] && count($linhaRel[$a0]['jogos']) === $linhaRel[$a0]['partidas']);
confere('relatório por jogador: a0 fez 2 gols na partida 1 e mais nas seguintes', $linhaRel[$a0]['gols'] >= 3 && $linhaRel[$a0]['jogos'][0]['numero'] === 1 && count($linhaRel[$a0]['jogos'][0]['gols']) === 2);
confere('relatório por jogador: gol contra fica com quem fez', $linhaRel[$b0]['gols_contra'] === 1);
$fechou = true;
foreach (array_merge($ordemChegada, ['Lucas']) as $nome) {
    $idj = $nome === 'Lucas' ? $idsJog['Lucas'] : $idsJog[$nome];
    $l = $linhaRel[$idj];
    $fechou = $fechou && ($l['partidas'] + $l['esperou'] === 3);
}
confere('relatório por jogador: partidas jogadas + partidas de fora = 3 para quem chegou antes', $fechou);
confere('relatório: quem veio do time que perdeu aparece marcado', in_array(true, array_column($linhaRel[$time2Ids[0]]['jogos'], 'veio_do_saiu'), true));
confere('relatório: jogador que não jogou ainda aparece com zero', isset($linhaRel[$idAtrasado]) && $linhaRel[$idAtrasado]['partidas'] === 0);
confere('outro admin não vê o relatório', erro(function () use ($u2, $idJogo) { (new Partidas($u2))->relatorioDoJogo($idJogo); }) !== null);

echo "Ranking do grupo\n";
$rk = $part->ranking($idGrupo);
confere('ranking conta 3 partidas encerradas e 10 gols', $rk['totais']['partidas'] === 3 && $rk['totais']['gols'] === 10);
confere('artilheiro em primeiro (a0, pelo menos 2 gols)', $rk['jogadores'][0]['gols'] >= 2 && $rk['jogadores'][0]['id_jogador'] === $a0);
$linhaA0 = null;
foreach ($rk['jogadores'] as $l) {
    if ($l['id_jogador'] === $a0) { $linhaA0 = $l; }
}
confere('jogador a0: ganhou a 1, perdeu a 2 e V+E+D fecha com o total', $linhaA0 && $linhaA0['partidas'] >= 2 && $linhaA0['vitorias'] === 1 && $linhaA0['derrotas'] >= 1 && $linhaA0['vitorias'] + $linhaA0['empates'] + $linhaA0['derrotas'] === $linhaA0['partidas']);
confere('ranking por período funciona', $part->ranking($idGrupo, 'ano')['totais']['partidas'] === 3 && $part->ranking($idGrupo, '30d')['totais']['partidas'] === 3);
confere('outro admin não vê o ranking', erro(function () use ($u2, $idGrupo) { (new Partidas($u2))->ranking($idGrupo); }) !== null);

echo "Fechamento\n";
$pg = new Pagamentos($u1);
confere('valor inválido recusado', erro(function () use ($pg, $idJogo) { $pg->finalizar($idJogo, 'abc'); }) !== null);
confere('outro admin não finaliza', erro(function () use ($u2, $idJogo) { (new Pagamentos($u2))->finalizar($idJogo, 100); }) !== null);
$res = $pg->finalizar($idJogo, '200,00');
confere('13 jogadores => R$ 15,39 (arredonda p/ cima)', $res['jogadores'] === 13 && abs($res['valor_por_jogador'] - 15.39) < 0.001);
confere('código pix com valor', strpos($res['codigo_pix'], '540515.39') !== false && substr($res['codigo_pix'], -4) === PixPayload::crc16(substr($res['codigo_pix'], 0, -4)));
$d = $jogos->detalhe($idJogo);
confere('situacao finalizado', $d['situacao'] === 'finalizado' && $d['fechamento']['total'] === 13);
confere('mensagem do whatsapp inclui o código', strpos($d['fechamento']['mensagem_whatsapp'], $res['codigo_pix']) !== false);
$pg->marcarPago($idJogo, $idsJog['Ana'], true);
$pg->marcarPago($idJogo, $idsJog['Bruno'], true);
$d = $jogos->detalhe($idJogo);
confere('2 pagos de 13', $d['fechamento']['pagos'] === 2);
confere('não recalcula com pagamento marcado', erro(function () use ($pg, $idJogo) { $pg->finalizar($idJogo, 300); }) !== null);
$pg->marcarPago($idJogo, $idsJog['Ana'], false);
$d = $jogos->detalhe($idJogo);
confere('desmarcar pagamento', $d['fechamento']['pagos'] === 1);
confere('jogo finalizado não aceita sorteio', erro(function () use ($timesSvc, $idJogo) { $timesSvc->sortear($idJogo); }) !== null);
confere('jogo finalizado não aceita chegada', erro(function () use ($pres, $idJogo, $idsJog) { $pres->chegada($idJogo, $idsJog['Ana'], false); }) !== null);

echo "Sem jogo / manual\n";
$idOutro = $ag['proximos'][1]['id'];
$jogos->cancelar($idOutro, 'Chuva forte');
$d2 = $jogos->detalhe($idOutro);
confere('cancelado com motivo', $d2['situacao'] === 'cancelado' && $d2['motivo_cancelamento'] === 'Chuva forte');
confere('cancelado recusa confirmação pública', erro(function () use ($pub, $d2, $pdo, $idOutro, $tel) {
    $t = $pdo->query("SELECT token FROM jogos WHERE id = $idOutro")->fetchColumn();
    $pub->publicoResponder($t, $tel, '', 'confirmado');
}) !== null);
confere('gerador não recria o dia cancelado', $rec->gerar(4) === 0);
$jogos->reativar($idOutro);
confere('reativado volta a agendado', $jogos->detalhe($idOutro)['situacao'] === 'agendado');

$idManual = $jogos->criar(['id_grupo' => $idGrupo, 'data' => date('Y-m-d', strtotime('+2 days')), 'hora' => '19:30']);
confere('jogo avulso criado', $idManual > 0);
confere('dois jogos no mesmo dia bloqueado', erro(function () use ($jogos, $idGrupo) { $jogos->criar(['id_grupo' => $idGrupo, 'data' => date('Y-m-d', strtotime('+2 days')), 'hora' => '21:00']); }) !== null);
confere('data inválida recusada', erro(function () use ($jogos, $idGrupo) { $jogos->criar(['id_grupo' => $idGrupo, 'data' => '2026-02-31', 'hora' => '19:30']); }) !== null);
$jogos->excluir($idManual);
confere('jogo avulso pode ser excluído', erro(function () use ($jogos, $idManual) { $jogos->detalhe($idManual); }) !== null);

echo "Jogador com histórico\n";
confere('remover jogador com histórico só inativa', $j1->remover($idGrupo, $idsJog['Ana']) === 'inativado');
$novo = $j1->salvar(['id_grupo' => $idGrupo, 'nome' => 'Sem Historico', 'whatsapp' => '62977776666']);
confere('remover jogador sem histórico apaga o vínculo', $j1->remover($idGrupo, $novo) === 'removido');

echo "Link de inscrição\n";
$ins = new Inscricoes($u1);
$pub = new Inscricoes();
$link = $g1->buscar($idGrupo)['link_inscricao'];
confere('grupo tem link de inscrição', (bool) preg_match('/tela=inscricao&g=[0-9a-f]{32}$/', $link));
$tok = substr($link, -32);
confere('página pública mostra o grupo', $pub->publicoInfo($tok)['grupo_nome'] !== '' && $pub->publicoInfo($tok)['aberta'] === true);
confere('inscrição sem nome recusada', erro(function () use ($pub, $tok) { $pub->publicoInscrever($tok, ['whatsapp' => '62955550000']); }) !== null);
$r = $pub->publicoInscrever($tok, ['nome' => 'Novato', 'whatsapp' => '(62) 95555-0000', 'instagram' => '@novato']);
confere('inscrição enviada', $r['status'] === 'enviado');
confere('repetir a inscrição avisa que já está pendente', $pub->publicoInscrever($tok, ['nome' => 'Novato', 'whatsapp' => '62955550000'])['status'] === 'ja_pendente');
$novato = array_values(array_filter($j1->listing($idGrupo), function ($j) { return $j['nome'] === 'Novato'; }))[0];
confere('aparece pendente e inativo na lista do grupo', $novato['pendente'] === 's' && $novato['ativo'] === 'n');
confere('membro do grupo só é avisado, sem duplicar', $pub->publicoInscrever($tok, ['nome' => 'Outro Nome', 'whatsapp' => $j1->listing($idGrupo)[1]['whatsapp']])['status'] !== 'enviado');
confere('outro admin não aprova', erro(function () use ($u2, $idGrupo, $novato) { (new Inscricoes($u2))->aprovar($idGrupo, $novato['id']); }) !== null);
$ins->aprovar($idGrupo, $novato['id']);
$novato = array_values(array_filter($j1->listing($idGrupo), function ($j) { return $j['nome'] === 'Novato'; }))[0];
confere('aprovado fica ativo', $novato['pendente'] === 'n' && $novato['ativo'] === 's');
confere('aprovar de novo dá erro', erro(function () use ($ins, $idGrupo, $novato) { $ins->aprovar($idGrupo, $novato['id']); }) !== null);
$pub->publicoInscrever($tok, ['nome' => 'Penetra', 'whatsapp' => '62955551111']);
$penetra = array_values(array_filter($j1->listing($idGrupo), function ($j) { return $j['nome'] === 'Penetra'; }))[0];
$ins->recusar($idGrupo, $penetra['id']);
confere('recusado sai do grupo', count(array_filter($j1->listing($idGrupo), function ($j) { return $j['nome'] === 'Penetra'; })) === 0);
$ins->abrir($idGrupo, false);
confere('inscrições fechadas recusam', erro(function () use ($pub, $tok) { $pub->publicoInscrever($tok, ['nome' => 'X', 'whatsapp' => '62955552222']); }) !== null);
$ins->abrir($idGrupo, true);
$linkNovo = $ins->novoLink($idGrupo);
confere('link novo invalida o anterior', $linkNovo !== $link && erro(function () use ($pub, $tok) { $pub->publicoInfo($tok); }) !== null);

echo "Meus dados\n";
$perfil = new Perfil($u1);
$meus = $perfil->dados();
confere('mostra os dados da conta', $meus['email'] === 'um@teste.com' && $meus['telefone'] === '5562911111111');
confere('lista os grupos que administra', count($meus['grupos']) >= 1 && $meus['grupos'][0]['dono'] === true);
confere('e-mail de outra conta recusado', erro(function () use ($perfil) { $perfil->salvar(['nome' => 'Admin Um', 'email' => 'dois@teste.com', 'telefone' => '62911111111']); }) !== null);
confere('WhatsApp de outra conta recusado', erro(function () use ($perfil) { $perfil->salvar(['nome' => 'Admin Um', 'email' => 'um@teste.com', 'telefone' => '62922222222']); }) !== null);
$sessao = $perfil->salvar(['nome' => 'Admin Um Novo', 'email' => 'UM@teste.com', 'telefone' => '(62) 91111-0000']);
confere('salva nome e WhatsApp novos', $sessao['nome'] === 'Admin Um Novo' && $perfil->dados()['telefone'] === '5562911110000');
confere('senha atual errada recusada', erro(function () use ($perfil) { $perfil->trocarSenha(['senha_atual' => 'errada', 'senha_nova' => 'novaSenha123']); }) !== null);
$perfil->trocarSenha(['senha_atual' => 'senha1234', 'senha_nova' => 'novaSenha123']);
confere('entra com a senha nova', $login->autenticar(['email' => 'um@teste.com', 'senha' => 'novaSenha123'])['id'] === $u1['id']);

echo "Grupo excluído\n";
$g1->excluir($idGrupo);
confere('grupo excluído some da listagem', count($g1->listing()) === 0);
confere('jogos do grupo excluído inacessíveis', erro(function () use ($jogos, $idJogo) { $jogos->detalhe($idJogo); }) !== null);

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas ? 1 : 0);
