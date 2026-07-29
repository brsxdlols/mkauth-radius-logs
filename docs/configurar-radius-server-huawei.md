# Configurar Huawei BNG/NE8K com o RADIUS do MK-Auth

Este guia mostra um exemplo de autenticação PPPoE entre um Huawei BNG/NE8K e o FreeRADIUS do MK-Auth.

## Endereços usados no exemplo

| Item | Valor |
| --- | --- |
| Servidor RADIUS/MK-Auth | `172.16.88.2` |
| IP de origem e NAS do Huawei | `45.170.122.1` |
| Porta de autenticação | UDP `1812` |
| Porta de contabilização | UDP `1813` |
| Porta de autorização/CoA | UDP `3799` |
| Grupo RADIUS no Huawei | `radius-server-pppoe` |
| Chave compartilhada de exemplo | `radius@bng` |

> A chave `radius@bng` é somente um exemplo. Em produção, use uma chave forte e diferente, configurada exatamente igual no Huawei e no ramal do MK-Auth.

## 1. Configuração no Huawei

Adapte os endereços, a interface LoopBack e a chave antes de aplicar:

```text
radius-server group radius-server-pppoe
 radius-server shared-key-cipher radius@bng
 radius-server authentication 172.16.88.2 source ip-address 45.170.122.1 1812 weight 0
 radius-server accounting 172.16.88.2 source ip-address 45.170.122.1 1813 weight 0
 radius-server retransmit 5 timeout 20
 radius-server source interface LoopBack1
 radius-server nas-ip-address 45.170.122.1
 radius-server user-name original
 radius-server user-name trust-server-request
 radius-server nas-port-id include interface-description delimiter - pe-vlan
#
radius local-ip 45.170.122.1
undo radius local-ip all
radius-server authorization 172.16.88.2 destination-port 3799 shared-key-cipher radius@bng server-group radius-server-pppoe
#
```

Em versões que exigem confirmação das alterações, execute `commit`.

O comando `undo radius local-ip all` remove a escuta genérica e mantém o endereço específico configurado em `radius local-ip 45.170.122.1`. Confirme a sintaxe disponível na versão VRP do equipamento.

## 2. Cadastrar o ramal correto no MK-Auth

Antes do teste, abra:

```text
https://SEU-MKAUTH/admin/ramais.hhvm
```

Crie ou altere um ramal/NAS com:

| Campo no MK-Auth | Valor do exemplo |
| --- | --- |
| IP do ramal/NAS | `45.170.122.1` |
| Nome curto | `NE8K` ou outro nome descritivo |
| Tipo | `other`, quando não existir uma opção específica para Huawei |
| Segredo/chave | `radius@bng` |
| Descrição | `Huawei BNG PPPoE` |

O IP do ramal no MK-Auth deve ser o IP que chega como origem/NAS do Huawei: `45.170.122.1`. Não cadastre `172.16.88.2` como ramal, pois esse é o endereço do próprio servidor RADIUS.

Garanta também que:

- o MK-Auth recebe UDP `1812` e `1813` a partir de `45.170.122.1`;
- o Huawei recebe UDP `3799` para CoA/Disconnect;
- a chave compartilhada é idêntica nos dois lados;
- existe no MK-Auth um cliente de teste ativo com login `ne8k` e senha `1`.

## 3. Testar a autenticação no Huawei

Execute no Huawei:

```text
test-aaa ne8k 1 radius-group radius-server-pppoe chap
```

Esse comando envia uma tentativa CHAP usando o grupo configurado. O resultado esperado é autenticação bem-sucedida/`Access-Accept`.

Depois do teste, abra **Radius Logs** no MK-Auth. A tentativa deve aparecer como conectada ou incorreta:

- **Timeout/sem resposta:** confira rota, firewall, portas e IP de origem.
- **Access-Reject/login incorreto:** confira cliente de teste, senha e estado do cadastro.
- **Erro de autenticador/segredo:** confira a chave compartilhada do Huawei e do ramal.
- **Unknown client/NAS desconhecido:** confira se o IP do ramal é `45.170.122.1`.

## Referências Huawei

- [Comando `test-aaa` e comandos AAA](https://support.huawei.com/enterprise/en/doc/EDOC1100412529/c0449dc0/aaa-configuration-commands)
- [Verificação da configuração do servidor RADIUS](https://info.support.huawei.com/network/ptmngsys/Web/tsrev_ne/pt/content/ne/68_edesk_ne_radius_dynamic_acl_does_not_take_effect/edesk_ne_radius_dynamic_acl_does_not_take_effect_edesk001.html)
