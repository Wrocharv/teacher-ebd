<?php
// ===============================================
//  TEACHER EBD — CONFIGURAÇÃO
//  Edite SÓ os valores entre aspas abaixo.
// ===============================================
return [

    // 1) Sua chave da IA (Anthropic). Começa com "sk-ant-".
    //    Pegue em: https://console.anthropic.com  →  API Keys
    'chave_ia' => 'COLE_AQUI_SUA_CHAVE',

    // 2) Senhas de acesso dos professores (pode ter mais de uma).
    //    Também é o CÓDIGO DE CONVITE para os professores criarem a conta.
    'senhas' => [
        'TROQUE_ESTA_SENHA',
    ],

    // 3) Quantos pedidos à IA o site aceita por dia (protege seus créditos).
    //    Cada aula completa usa 4 pedidos; cada pergunta no chat usa 1.
    'limite_diario' => 60,

    // 4) Modelo da IA (não precisa mexer).
    'modelo' => 'claude-sonnet-5-5',
];
