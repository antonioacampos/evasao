<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use \Uspdev\Replicado\DB;

class Evasao extends Model
{
    use HasFactory;

    public static function listarIngressantes(int $ano)
    {
        #-- query para gerar a lista de alunos a serem processadas.
        #-- a quantidade retornada será a quantidade de linhas da planilha final
        $query = "SELECT p.codpes, p.codpgm, p.tiping, p.tipencpgm,
            CONVERT(VARCHAR(10),p.dtaing ,103) AS data1, p.clsing, p.stapgm,
            CONVERT(VARCHAR(10),p.dtaini ,103) AS data2, p.tipencpgm,
            h.codcur, h.codhab, CONVERT(VARCHAR(10),h.dtaini ,103) AS data3, h.clsdtbalutur,
            CONVERT(VARCHAR(10),h.dtafim ,103) AS data4, h.tipenchab,
            c.nomcur, a.nomhab
            FROM PROGRAMAGR AS p
            JOIN HABILPROGGR AS h ON (p.codpes = h.codpes AND p.codpgm = h.codpgm)
            JOIN CURSOGR AS c ON (h.codcur = c.codcur)
            JOIN HABILITACAOGR as a ON (h.codhab = a.codhab AND c.codcur = a.codcur)
            WHERE
            c.codclg IN (18, 90, 97) AND
            p.dtaing >= '{$ano}-01-01' AND p.dtaing <= '{$ano}-12-31' -- ingresso no ano
            ORDER BY
            p.codpes, h.codcur, a.codhab;
        ";

        return DB::fetchAll($query);
    }

    public static function obterBeneficiosFormatado($codpes, $dataini, $datafim)
    {
        if ($beneficios = Evasao::listarBeneficios($codpes, $dataini, $datafim)) {
            $ret = '';
            foreach ($beneficios as $b) {
                $ret .= "{$b['nome']} ({$b['data_inicio']}-{$b['data_fim']}), ";
            }
            $ret = substr($ret, 0, -2);
        } else {
            $ret = 'não';
        }
        return $ret;
    }

    public static function listarBeneficios($codpes, $dataini, $datafim)
    {
        $query = "SELECT b.codbnfalu AS cod, b.tipbnfalu AS tipo, b.nombnfloc AS nome,
                CONVERT(VARCHAR(10),a.dtainiccd ,103) AS data_inicio,
                CONVERT(VARCHAR(10), a.dtafimccdori ,103) AS data_fim, a.vlrbnfepfbls AS valor
            FROM BENEFICIOALUCONCEDIDO a
            JOIN BENEFICIOALUNO b ON (a.codbnfalu = b.codbnfalu)
            WHERE a.codpes=:codpes
                AND a.dtainiccd >= :dataini AND a.dtafimccdori <= :datafim -- periodo do beneficio
            ORDER BY a.dtafimccd DESC";

        $param = [
            'codpes' => $codpes,
            'dataini' => $dataini,
            'datafim' => $datafim,
        ];

        return DB::fetchAll($query, $param);
    }

    /**
     * <p>
     * Método que retorna as médias de um aluno específico
     * Conforme Grad::obterMediasAlunoGrad
     * </p>
     *
     *
     * @param $nusp nusp do aluno validado
     * @param $porSemestre: boolean, agrupar por semestre, default false
     * @param $entrada: referente a matrícula
     * @return medias
     */
    public static function obterMediasAlunoGradGeral($codpes, $porSemestre = false, $codpgm = 1)
    {
        $entrada = $codpgm;
        $sql = "SELECT  SUBSTRING(h.codtur, 1, 5) AS semestre, h.codtur, h.coddis, d.nomdis,
                    h.codpes, h.stamtr, h.rstfim , d.creaul, d.cretrb, h.notfim AS nota1, h.notfim2 AS nota2
                FROM HISTESCOLARGR AS h, TURMAGR AS t, DISCIPLINAGR AS d, HABILPROGGR AS g
                WHERE t.coddis = d.coddis AND h.codpes = :codpes AND h.codtur = t.codtur AND h.coddis = t.coddis
                    AND h.verdis = t.verdis AND h.verdis = d.verdis  AND h.rstfim IS NOT NULL
                    AND g.codpes = h.codpes AND g.codpgm = h.codpgm  AND g.codpgm = :codpgm
                    AND h.rstfim NOT IN ('D', 'T')
                    AND h.stamtr='M'
                ORDER BY h.codtur, h.coddis ; ";

        $sql = "SELECT SUBSTRING(h.codtur, 1, 5) AS semestre, h.codtur, h.coddis, d.nomdis,
                    h.codpes, h.stamtr, h.rstfim , d.creaul, d.cretrb, h.notfim AS nota1, h.notfim2 AS nota2,
                    d.nomdis, d.creaul, d.cretrb
                    --h.*, d.*
                FROM HISTESCOLARGR h
                JOIN DISCIPLINAGR d ON (h.verdis = d.verdis AND h.coddis = d.coddis)
                WHERE h.stamtr='M' --efeticamente matriculado
                    AND h.rstfim not in ('D', 'T') AND h.rstfim IS NOT NULL --resultado final: D-equivalencia, T-trancamento
                    AND h.codpgm = :codpgm -- codigo-programa = numero do ingresso
                    AND h.codpes=:codpes";

        $param['codpes'] = $codpes;
        $param['codpgm'] = $codpgm;

        $disciplinas = DB::fetchAll($sql, $param);

        //print_r($disciplinas);exit;
        $ret = array();
        $mediaSuja = 0;
        $somaNotaSuja = 0;
        $mediaLimpa = 0;
        $somaNotaLimpa = 0;
        $qtdTotal = 0;
        $qtdAprovada = 0;
        $ponderacao = 0;

        $totalDiscAprov = 0;
        $totalDiscRepr = 0;

        $semestre = array();
        $s = [
            'disciplinas' => 0,
            'mediaSuja' => 0,
            'somaNotaSuja' => 0,
            'mediaLimpa' => 0,
            'somaNotaLimpa' => 0,
            'qtdTotal' => 0,
            'qtdAprovada' => 0,
            'ponderacao' => 0,
        ];
        $semestreAtual = 'BLA';

        foreach ($disciplinas as $row) {
            $row = (object) $row;
            $ponderacaoDisciplina = 0;

            //verificando se mudou de semestre
            if ($semestreAtual != $row->semestre) {
                //calcular os valores finais deste semestre
                if ($s['qtdAprovada'] > 0) {
                    $s['mediaLimpa'] = $s['somaNotaLimpa'] / $s['qtdAprovada'];
                }

                if ($s['qtdTotal'] > 0) {
                    $s['mediaSuja'] = ($s['somaNotaSuja'] + $s['somaNotaLimpa']) / $s['qtdTotal'];
                }

                //guardar o semestre no acumulador
                $semestre[$semestreAtual] = $s;

                // vamos começar todo de novo
                $semestreAtual = $row->semestre;
                $s = [
                    'disciplinas' => 0,
                    'mediaSuja' => 0,
                    'somaNotaSuja' => 0,
                    'mediaLimpa' => 0,
                    'somaNotaLimpa' => 0,
                    'qtdTotal' => 0,
                    'qtdAprovada' => 0,
                    'ponderacao' => 0,
                ];
            }

            // processando as medias para um semestre
            $s['disciplinas'] += 1;
            $notaPonderadaDisciplina = 0;
            $ponderacaoDisciplina = ($row->creaul + $row->cretrb);

            if ($row->nota2 && ($row->nota2 > $row->nota1)) { //teve recuperação
                $notaPonderadaDisciplina = ($row->nota2) * $ponderacaoDisciplina;
            } else { // nota normal
                $notaPonderadaDisciplina = ($row->nota1) * $ponderacaoDisciplina;
            }

            if (trim($row->rstfim) == 'A') { // se foi aprovado/reprovado
                $s['somaNotaLimpa'] += $notaPonderadaDisciplina;
                $s['qtdAprovada'] += $ponderacaoDisciplina;
            } else {
                $s['somaNotaSuja'] += $notaPonderadaDisciplina;
            }

            $s['qtdTotal'] += $ponderacaoDisciplina;

            // processando as medias gerais
            $notaPonderada = 0;
            $ponderacao = ($row->creaul + $row->cretrb);
            if ($row->nota2 && ($row->nota2 > $row->nota1)) { //teve recuperação
                $notaPonderada = ($row->nota2) * $ponderacao;
            } else { // nota normal
                $notaPonderada = ($row->nota1) * $ponderacao;
            }

            if (trim($row->rstfim) == 'A') {
                $somaNotaLimpa += $notaPonderada;
                $qtdAprovada += $ponderacao;
                $totalDiscAprov++;
            } else {
                $somaNotaSuja += $notaPonderada;
            }

            if (trim($row->rstfim) != 'A') {
                $totalDiscRepr++;
            }

            $qtdTotal += $ponderacao;
        }

        //ÚLTIMO SEMESTRE...
        //calcular os valores finais deste semestre
        if ($s['qtdAprovada'] > 0) {
            $s['mediaLimpa'] = $s['somaNotaLimpa'] / $s['qtdAprovada'];
        }

        if ($s['qtdTotal'] > 0) {
            $s['mediaSuja'] = ($s['somaNotaSuja'] + $s['somaNotaLimpa']) / $s['qtdTotal'];
        }

        //guardar o semestre no acumulador
        $semestre[$semestreAtual] = $s;

        if ($qtdAprovada > 0) {
            $mediaLimpa = $somaNotaLimpa / $qtdAprovada;
        }

        if ($qtdTotal > 0) {
            $mediaSuja = ($somaNotaSuja + $somaNotaLimpa) / $qtdTotal;
        }
        //print_r($semestre);exit;

        if ($porSemestre) {
            unset($semestre['BLA']);
            $ret = array('mediaPonderadaLimpa' => sprintf("%01.1f", $mediaLimpa), 'mediaPonderadaSuja' => sprintf("%01.1f", $mediaSuja), 'semestres' => $semestre);
        } else {
            $ret = array('mediaPonderadaLimpa' => sprintf("%01.1f", $mediaLimpa), 'mediaPonderadaSuja' => sprintf("%01.1f", $mediaSuja));
        }

        // numero de disciplinas
        $ret['entrada'] = $entrada;
        $ret['totalDiscRepr'] = $totalDiscRepr;
        $ret['totalDiscAprov'] = $totalDiscAprov;
        $ret['qtdTotal'] = count($disciplinas);

        //print_r($ret);exit;
        return $ret;

    }

    // nao vai precisar pois vai usar do datatables
    public static function toCsv($colecao, $filename)
    {
        $headers = array(
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0",
        );

        $callback = function () use ($colecao) {
            $output = fopen("php://output", 'w') or die("Can't open php://output");
            foreach ($colecao as $row) {

                fputcsv($output, [
                    $row['ano'],
                    $row['curso'],
                    $row['tiping'],
                    $row['codpes'],
                    $row['status'],
                    $row['tipenchab'],
                    $row['beneficio'],
                    $row['totalDiscRepr'],
                    $row['totalDiscAprov'],
                    $row['mediaPonderadaSuja'],
                    $row['mediaPonderadaLimpa'],
                ]);

            }
            fclose($output) or die("Can't close php://output");
        };

        return response()->stream($callback, 200, $headers);
    }
}