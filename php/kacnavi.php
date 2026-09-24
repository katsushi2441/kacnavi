<?php
/**
 * Kurage AfterCare Navi（kacnavi）— 身内が亡くなったあとの手続きナビ
 *
 * PHP1ファイル＋SQLite。故人の状況を選ぶと、必要な手続きを期限の近い順に返す。
 * 期限は法令の条文まで降りて確かめたものだけを載せる（scripts/verify_law.py が毎回検算する）。
 *
 * 置き方: このファイルと kacnavi_data/kacnavi.sqlite を同じ階層に置く。
 *         .htaccess に AddHandler php-script .php（heteml の既定は PHP 5.6）。
 *
 * heteml の SQLite には R*Tree も FTS5 も無いことがあるので、普通の表と索引だけを使う。
 */

$SELF   = '/kacnavi.php';
$SITE   = 'Kurage AfterCare Navi';
$SUB    = '身内が亡くなったあとの手続きナビ';
$OGP    = 'https://kurage.exbridge.jp/images/ogp/kacnavi.png';
$DBPATH = __DIR__ . '/kacnavi_data/kacnavi.sqlite';
$BASE   = 'https://kurage.exbridge.jp' . $SELF;

mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=UTF-8');

try {
    $db = new PDO('sqlite:' . $DBPATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><p>準備中です。</p>';
    exit;
}

$META = array();
foreach ($db->query('SELECT k, v FROM meta') as $r) { $META[$r['k']] = $r['v']; }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((int)$v); }
function jd($s) { $v = json_decode((string)$s, true); return is_array($v) ? $v : array(); }

/** 期限の重さ。赤=過ぎると取り返しがつかない、橙=お金が消える、灰=いつでも */
function dl_class($p) {
    if ($p['dl_kind'] === '期限' && $p['dl_days'] !== null && $p['dl_days'] <= 100) { return 'lv3'; }
    if ($p['dl_kind'] === '期限') { return 'lv2'; }
    if ($p['dl_kind'] === '時効') { return 'lv2'; }
    return '';
}

function where_label($k) {
    static $L = array(
        'city' => '市区町村の窓口', 'nenkin' => '年金事務所', 'zeimu' => '税務署',
        'houmu' => '法務局', 'katei' => '家庭裁判所', 'bank' => '金融機関・証券会社',
        'hoken' => '保険会社', 'unyu' => '運輸支局・軽自動車検査協会', 'keisatsu' => '警察署',
        'kaisha' => '勤め先', 'hospital' => '病院', 'nogyo' => '農業委員会', 'other' => 'その他',
    );
    return isset($L[$k]) ? $L[$k] : 'その他';
}

/**
 * 選んだ条件から、必要な手続きを選ぶ。
 * need は全部満たすこと、need_any はどれか1つ。どちらも空なら誰にでも要る手続き。
 * unneeded_if に当たるときは消さずに「要らないかもしれない」と印をつける（消すと不安になる）。
 */
function pick($db, $sel) {
    $out = array();
    // **何も選んでいない人には全部見せる。** 絞り込みは「要らないものを消す」機能であって、
    // 選ぶ前から隠すためのものではない。条件を1つも選ばずに来た人は、まず全体像を見たい。
    $none = !$sel;
    foreach ($db->query('SELECT * FROM procedures ORDER BY sort') as $p) {
        $need = jd($p['need']); $any = jd($p['need_any']);
        if ($none) {
            $p['_maybe_unneeded'] = false;
            $p['_everyone'] = (!$need && !$any);
            $out[] = $p;
            continue;
        }
        $ok = true;
        foreach ($need as $t) { if (!in_array($t, $sel, true)) { $ok = false; break; } }
        if ($ok && $any) {
            $hit = false;
            foreach ($any as $t) { if (in_array($t, $sel, true)) { $hit = true; break; } }
            $ok = $hit;
        }
        if (!$ok) { continue; }
        $p['_maybe_unneeded'] = false;
        foreach (jd($p['unneeded_if']) as $t) { if (in_array($t, $sel, true)) { $p['_maybe_unneeded'] = true; } }
        $p['_everyone'] = (!$need && !$any);
        $out[] = $p;
    }
    return $out;
}

/** 自由文から条件を拾う。ここは決定論（辞書の照合）。手元のLLMに読ませる経路は別建て。 */
function conditions_from_text($db, $text) {
    if ($text === '') { return array(); }
    $hit = array();
    foreach ($db->query('SELECT id, kw FROM conditions') as $c) {
        foreach (jd($c['kw']) as $w) {
            if ($w !== '' && mb_strpos($text, $w) !== false) { $hit[] = $c['id']; break; }
        }
    }
    return $hit;
}

function conditions_all($db) {
    $out = array();
    foreach ($db->query('SELECT * FROM conditions ORDER BY sort') as $c) { $out[$c['cat']][] = $c; }
    return $out;
}

function city_by_slug($db, $pref, $slug) {
    $st = $db->prepare('SELECT * FROM cities WHERE pref = ? AND slug = ? LIMIT 1');
    $st->execute(array($pref, $slug));
    return $st->fetch();
}

function head_html($title, $desc, $canon, $ld_extra = null) {
    global $SELF, $SITE, $SUB, $OGP, $BASE, $META;
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<meta name="description" content="' . h($desc) . '">';
    echo '<link rel="canonical" href="' . h($BASE . $canon) . '">';
    echo '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '">';
    echo '<meta property="og:type" content="website"><meta property="og:image" content="' . h($OGP) . '">';
    echo '<meta property="og:site_name" content="' . h($SITE) . '"><meta property="og:url" content="' . h($BASE . $canon) . '">';
    echo '<meta property="og:locale" content="ja_JP">';
    echo '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="' . h($OGP) . '">';
    echo '<style>'
       . ':root{--ink:#1d2430;--mut:#616c7a;--ac:#3f6f8f;--ac-d:#2f5670;--line:#e0e5ea;--bg:#f6f7f5;'
       . '--red:#a5453a;--red-l:#fbeeec;--amb:#8a6a1f;--amb-l:#fbf4e4;--grn-l:#eef4ef}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.85 "Noto Serif JP",Georgia,"Hiragino Mincho ProN",serif}'
       . 'a{color:var(--ac-d)}.wrap{width:min(920px,100% - 32px);margin:0 auto}'
       . 'header{background:#fff;border-bottom:1px solid var(--line)}'
       . '.brand{display:block;padding:16px 0 4px;font-weight:700;font-size:19px;text-decoration:none;color:var(--ink)}'
       . '.brand small{display:block;font-weight:400;font-size:13px;color:var(--mut);letter-spacing:.04em}'
       . '.menu{display:flex;gap:16px;flex-wrap:wrap;padding-bottom:12px;font-size:14px}'
       . '.menu a{text-decoration:none;color:var(--mut)}'
       . 'main{padding:24px 0 48px}'
       . 'h1{font-size:26px;line-height:1.5;margin:0 0 12px;text-wrap:balance}'
       . 'h2{font-size:20px;margin:34px 0 12px;padding-bottom:6px;border-bottom:2px solid var(--line)}'
       . 'h3{font-size:17px;margin:20px 0 8px}'
       . '.lead{color:var(--mut);font-size:15px}'
       . '.panel{background:#fff;border:1px solid var(--line);border-radius:10px;padding:20px;margin:16px 0}'
       . '.panel.quiet{background:#fbfcfb}'
       . '.cond{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:6px 18px;margin:6px 0 2px}'
       . '.cond label{display:flex;gap:8px;align-items:flex-start;font-size:14.5px;line-height:1.6;cursor:pointer;padding:3px 0}'
       . '.cond input{margin-top:6px;flex:none;width:17px;height:17px}'
       . '.catname{font-size:12px;letter-spacing:.1em;color:var(--mut);margin:16px 0 2px;font-family:system-ui,sans-serif}'
       . '.btn{display:inline-block;background:var(--ac);color:#fff;border:0;border-radius:8px;padding:13px 26px;'
       . 'font:inherit;font-weight:700;text-decoration:none;cursor:pointer}'
       . '.btn.ghost{background:#fff;color:var(--ac-d);border:1px solid var(--line);padding:9px 16px;font-size:14px}'
       . 'input[type=text]{width:100%;font:inherit;font-size:16px;padding:12px 14px;border:1px solid var(--line);border-radius:8px;background:#fff}'
       . '.step{border-left:3px solid var(--line);padding:2px 0 2px 18px;margin:20px 0}'
       . '.step.lv3{border-color:var(--red)}.step.lv2{border-color:var(--amb)}'
       . '.item{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin:10px 0}'
       . '.item.lv3{border-left:4px solid var(--red)}.item.lv2{border-left:4px solid var(--amb)}'
       . '.item .nm{font-weight:700;font-size:17.5px;line-height:1.5}'
       . '.item .nm a{text-decoration:none;color:var(--ink)}'
       . '.dl{font-family:system-ui,sans-serif;font-size:12.5px;font-weight:700;display:inline-block;white-space:nowrap;'
       . 'border-radius:5px;padding:2px 9px;margin-right:8px;background:var(--bg);color:var(--mut);vertical-align:2px}'
       . '.dl.lv3{background:var(--red-l);color:var(--red)}.dl.lv2{background:var(--amb-l);color:var(--amb)}'
       . '.meta{font-size:13.5px;color:var(--mut);margin-top:6px;font-family:system-ui,sans-serif}'
       . '.why{margin-top:10px;font-size:15px}'
       . '.why strong{background:linear-gradient(transparent 62%,#f3e7c8 62%);font-weight:700}'
       . '.flag{display:inline-block;font-family:system-ui,sans-serif;font-size:12.5px;background:var(--grn-l);'
       . 'border:1px solid #cddbd0;border-radius:5px;padding:2px 9px;color:#3c6048;margin-right:6px}'
       . '.law{background:#fafbfa;border:1px solid var(--line);border-radius:8px;padding:12px 14px;margin:10px 0;font-size:14px}'
       . '.law .ttl{font-family:system-ui,sans-serif;font-size:12.5px;color:var(--mut);margin-bottom:4px}'
       . '.law blockquote{margin:0;font-size:14px;line-height:1.8;color:#3a4450}'
       . 'ul.docs{margin:8px 0 0;padding-left:20px;font-size:14.5px}'
       . '.src{font-size:12.5px;color:var(--mut);line-height:1.85;font-family:system-ui,sans-serif}'
       . '.tscroll{overflow-x:auto}table.t{width:100%;border-collapse:collapse;font-size:14px;min-width:420px}'
       . 'table.t th,table.t td{border-bottom:1px solid var(--line);padding:9px 10px;text-align:left;vertical-align:top}'
       . 'table.t th{color:var(--mut);font-size:12px;font-family:system-ui,sans-serif;font-weight:400}'
       . 'td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}'
       . '.cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:4px 14px;font-size:14.5px}'
       . '.big{font-size:30px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.3}'
       . 'footer{border-top:1px solid var(--line);padding:24px 0 48px;background:#fff}'
       . '@media(max-width:520px){h1{font-size:22px}body{font-size:15.5px}}'
       . '</style>';
    echo '<script>(function(){var s=document.createElement("script");s.src="https://kurage.exbridge.jp/simpletrack.php?url='
       . '"+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;'
       . 'document.head.appendChild(s)})();</script>';
    $graph = array(
        array('@type' => 'WebApplication', 'name' => $SITE, 'alternateName' => $SUB,
              'url' => $BASE . '/', 'applicationCategory' => 'GovernmentApplication',
              'operatingSystem' => 'Web', 'inLanguage' => 'ja',
              'description' => '故人の状況を選ぶと、必要な手続きを期限の近い順に返します。期限は法令の条文で裏を取っています。全国' . n($META['n_cities']) . '市区町村の窓口ページつき。',
              'offers' => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'JPY'),
              'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/')),
        array('@type' => 'FAQPage', 'mainEntity' => array(
            array('@type' => 'Question', 'name' => '死亡届の期限は亡くなった日から7日ですか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '違います。戸籍法86条1項は「届出義務者が、死亡の事実を知つた日から七日以内」と定めています。離れて暮らしていて数日後に知った場合は、知った日から7日です。国外で亡くなったときは知った日から3か月以内になります。')),
            array('@type' => 'Question', 'name' => '年金の死亡届は必ず出さないといけませんか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '出さなくてよい場合があります。マイナンバーが年金に登録されている受給権者について、死亡日から7日以内に戸籍法の死亡届が出されていれば、年金受給権者死亡届は不要です（厚生年金保険法98条4項ただし書、同施行規則41条5項・6項。国民年金も同じ構造）。ただし未支給年金の請求は別に必要です。')),
            array('@type' => 'Question', 'name' => '過ぎると取り返しがつかない期限はどれですか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '相続放棄・限定承認の3か月です（民法915条）。自分が相続人になったことを知った時から3か月を過ぎると、借金も含めて全部引き継いだことになります。財産を調べきれないときは、3か月以内に家庭裁判所へ期間の伸長を申し立てられます。')),
            array('@type' => 'Question', 'name' => '相続登記はいつまでにすればいいですか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '相続で所有権を取得したことを知った日から3年以内です（不動産登記法76条の2）。2024年4月1日から義務になりました。それより前に相続が起きた分は2027年3月31日までです。遺産分割がまとまらないときは、相続人申告登記を1人で申し出れば義務を果たしたことになります。')),
            array('@type' => 'Question', 'name' => '世帯主変更届はいつも必要ですか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '必要ない場合があります。残る世帯員が1人だけなら、その人が自動的に世帯主になるので届出は要りません。残るのが親と15歳未満の子だけのときも同じです。2人以上残って誰が世帯主になるか決まらないときだけ、14日以内に届け出ます（住民基本台帳法25条）。')))),
    );
    if ($ld_extra) {
        if (isset($ld_extra['@type'])) { $graph[] = $ld_extra; }
        else { foreach ($ld_extra as $x) { $graph[] = $x; } }
    }
    echo '<script type="application/ld+json">'
       . json_encode(array('@context' => 'https://schema.org', '@graph' => $graph),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    echo '</head><body><header><div class="wrap">';
    echo '<a class="brand" href="' . h($SELF) . '/">' . h($SITE) . '<small>' . h($SUB) . '</small></a>';
    echo '<nav class="menu">';
    foreach (array('/' => '状況から調べる', '/when/g0' => '時期の順に見る',
                   '/cities' => '市区町村の窓口', '/about' => 'このサイトについて') as $u => $t) {
        echo '<a href="' . h($SELF . $u) . '">' . h($t) . '</a>';
    }
    echo '</nav></div></header><main><div class="wrap">';
}

function foot_html() {
    global $META;
    echo '</div></main><footer><div class="wrap">';
    echo '<p class="src">期限の根拠は <a href="https://laws.e-gov.go.jp/" rel="nofollow">e-Gov法令検索</a>（デジタル庁）の条文。'
       . '市区町村の人口・世帯数・年間死亡者数は' . h($META['stats_source']) . '（人口は' . h($META['stats_asof_pop'])
       . '現在、人口動態は' . h($META['stats_asof_doutai']) . '）。いずれも政府標準利用規約に従って出典を示しています。<br>'
       . 'このサイトは手続きの<strong>目安</strong>です。金額・必要書類・受付時間は市区町村ごとに違います。'
       . '相続放棄や相続税など判断が要るものは、弁護士・司法書士・税理士にご相談ください。</p>';
    echo '<p class="src">提供: <a href="https://exbridge.jp/">株式会社エクスブリッジ</a>（名古屋市）／'
       . '内容の更新日 ' . h($META['asof']) . '</p>';
    echo '<p class="src">名古屋市内の会社なら、<a href="https://exbridge.jp/ai-it-komon.html?ref=kacnavi">AI-IT顧問契約</a>'
       . '（月15時間・税別150,000円）の期間中に構築できる商品は、商品代金をいただかず当社が設置まで行います。'
       . 'ソースコードごと御社の資産として残ります。</p>';
    echo '</div></footer></body></html>';
}

/** 手続き1件。$full=true で条文と書類まで出す。 */
function item_html($p, $full = false, $city = null) {
    global $SELF, $db;
    $cls = dl_class($p);
    echo '<div class="item ' . $cls . '">';
    echo '<div class="nm">';
    // 一覧では期限を短い形で出す。「7日以内（国外で亡くなったときは3か月以内）」のような
    // 但し書きまで並べると、1件が3行になって一覧が読めなくなる（390pxで実測）。
    $badge = $p['dl_text'];
    if (!$full && $p['dl_n'] && $p['dl_unit']) {
        $badge = ($p['dl_kind'] === '時効' ? '時効' : '') . $p['dl_n'] . $p['dl_unit'] . ($p['dl_kind'] === '時効' ? '' : '以内');
    } elseif (!$full && $p['dl_short']) {
        $badge = $p['dl_short'];
    } elseif (!$full && $p['dl_kind'] === 'なし') {
        $badge = '期限なし';
    }
    if ($badge) { echo '<span class="dl ' . $cls . '">' . h($badge) . '</span>'; }
    if ($full) { echo h($p['name']); }
    else { echo '<a href="' . h($SELF . '/p/' . rawurlencode($p['id'])) . '">' . h($p['name']) . '</a>'; }
    echo '</div>';
    if (!empty($p['_maybe_unneeded']) && $p['unneeded_text']) {
        echo '<div style="margin-top:8px"><span class="flag">出さなくてよい場合があります</span>'
           . '<span style="font-size:14.5px">' . h($p['unneeded_text']) . '</span></div>';
    }
    $where = $p['where_txt'];
    if ($city && $p['where_kind'] === 'city') {
        $where = $city['pref'] . $city['city'] . ($city['seirei_ku'] ? '（区役所）' : 'の窓口');
    }
    echo '<div class="meta">' . h($where);
    if ($p['who']) { echo '　／　届け出る人: ' . h($p['who']); }
    if ($p['fee']) { echo '　／　費用: ' . h($p['fee']); }
    echo '</div>';
    if ($full) {
        if ($p['dl_from']) { echo '<div class="meta">数え始める日: ' . h($p['dl_from']) . '</div>'; }
        echo '<div class="why">' . nl2br(preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', h($p['why']))) . '</div>';
        $docs = jd($p['docs']);
        if ($docs) {
            echo '<h3>持っていくもの</h3><ul class="docs">';
            foreach ($docs as $d) { echo '<li>' . h($d) . '</li>'; }
            echo '</ul>';
        }
        $laws = jd($p['law']);
        if ($laws) {
            echo '<h3>根拠の条文</h3>';
            foreach ($laws as $l) {
                $st = $db->prepare('SELECT * FROM law_articles WHERE law = ? AND article = ?');
                $st->execute(array($l['name'], $l['article']));
                $a = $st->fetch();
                if (!$a) { continue; }
                $st2 = $db->prepare('SELECT * FROM laws WHERE name = ?');
                $st2->execute(array($l['name']));
                $lw = $st2->fetch();
                echo '<div class="law"><div class="ttl">' . h($l['name']) . ' ' . h($a['title'])
                   . ($a['caption'] ? '（' . h($a['caption']) . '）' : '') . '</div>';
                echo '<blockquote>' . h(mb_substr(preg_replace('/\s+/u', ' ', $a['text']), 0, 320))
                   . (mb_strlen($a['text']) > 320 ? '…' : '') . '</blockquote>';
                if ($lw) {
                    echo '<div class="ttl" style="margin:6px 0 0">'
                       . '<a href="' . h($lw['url']) . '" rel="nofollow">e-Gov法令検索で全文を読む</a>'
                       . ($lw['enforcement'] ? '（' . h($lw['enforcement']) . ' 施行時点）' : '') . '</div>';
                }
                echo '</div>';
            }
        }
    } else {
        $why = preg_replace('/\*\*/u', '', strtok($p['why'], "\n"));
        $cut = mb_substr($why, 0, 72);
        echo '<div class="why" style="color:#4b5663;font-size:14px;margin-top:5px">' . h($cut)
           . (mb_strlen($why) > 72 ? '…' : '') . '</div>';
    }
    echo '</div>';
}

/** 手続きを表で出す。件数が多いところはカードでなく表にする（スマホで読める長さにするため）。 */
function table_html($rows, $show_where = true) {
    global $SELF;
    echo '<div class="tscroll"><table class="t">';
    echo '<tr><th style="width:40%">手続き</th><th style="width:26%">期限</th>'
       . ($show_where ? '<th>窓口</th>' : '<th>ひとこと</th>') . '</tr>';
    foreach ($rows as $r) {
        $badge = $r['dl_n'] && $r['dl_unit']
            ? (($r['dl_kind'] === '時効' ? '時効' : '') . $r['dl_n'] . $r['dl_unit'] . ($r['dl_kind'] === '時効' ? '' : '以内'))
            : ($r['dl_short'] ? $r['dl_short'] : ($r['dl_kind'] === 'なし' ? '期限なし' : $r['dl_text']));
        $why = preg_replace('/\*\*/u', '', strtok($r['why'], "\n"));
        echo '<tr><td><a href="' . h($SELF . '/p/' . rawurlencode($r['id'])) . '">' . h($r['name']) . '</a></td>'
           . '<td><span class="dl ' . dl_class($r) . '">' . h($badge) . '</span></td>'
           . '<td style="font-size:13px;color:var(--mut)">'
           . h($show_where ? where_label($r['where_kind']) : mb_substr($why, 0, 46) . (mb_strlen($why) > 46 ? '…' : ''))
           . '</td></tr>';
    }
    echo '</table></div>';
}

// ---- ルーティング ---------------------------------------------------------
$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/';
$path = rtrim($path, '/');
if ($path === '') { $path = '/'; }

// 選んだ条件（c=... のカンマ区切り）と自由文（q=...）
$raw = isset($_GET['c']) ? (string)$_GET['c'] : '';
$sel = array_values(array_filter(array_map('trim', explode(',', $raw))));
// JavaScript が無くても動くように、素の checkbox（k[]）も受ける。
if (isset($_GET['k']) && is_array($_GET['k'])) {
    foreach ($_GET['k'] as $v) { $sel[] = trim((string)$v); }
}
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($q !== '') { $sel = array_values(array_unique(array_merge($sel, conditions_from_text($db, $q)))); }
$valid = array();
foreach ($db->query('SELECT id FROM conditions') as $r) { $valid[] = $r['id']; }
$sel = array_values(array_intersect($sel, $valid));

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    $lm = date('Y-m-d', filemtime($DBPATH));
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $urls = array('/', '/cities', '/about');
    foreach ($db->query('SELECT id FROM groups ORDER BY sort') as $g) { $urls[] = '/when/' . $g['id']; }
    foreach ($db->query('SELECT id FROM procedures ORDER BY sort') as $p) { $urls[] = '/p/' . rawurlencode($p['id']); }
    foreach ($db->query('SELECT DISTINCT pref FROM cities') as $r) { $urls[] = '/pref/' . rawurlencode($r['pref']); }
    foreach ($db->query('SELECT pref, slug FROM cities ORDER BY code') as $r) {
        $urls[] = '/city/' . rawurlencode($r['pref']) . '/' . rawurlencode($r['slug']);
    }
    foreach ($urls as $u) {
        echo '<url><loc>' . h($BASE . $u) . '</loc><lastmod>' . $lm . '</lastmod></url>';
    }
    echo '</urlset>';
    exit;
}

if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "# " . $SITE . "（" . $SUB . "）\n\n";
    echo "故人の状況を選ぶと、必要な手続きを期限の近い順に返します。\n";
    echo "期限は e-Gov法令検索（デジタル庁）の条文で裏を取り、scripts/verify_law.py が毎回検算しています。\n\n";
    echo "## 覚えておくとよい事実\n";
    echo "- 死亡届の起算日は亡くなった日ではなく、死亡の事実を知った日（戸籍法86条1項）。\n";
    echo "- マイナンバーが年金に登録されていれば、年金受給権者死亡届は原則不要（厚生年金保険法98条4項ただし書）。\n";
    echo "- 相続放棄の3か月（民法915条）が、過ぎると取り返しのつかない唯一の期限。\n";
    echo "- 相続登記の3年（不動産登記法76条の2）は2024年4月1日施行。古い解説と食い違う。\n";
    echo "- 山林を相続したら90日以内に市町村へ届出（森林法10条の7の2・同施行規則7条）。\n";
    echo "- 農地の届出は「遅滞なく」（農地法3条の3）。10か月は相続税の期限で別の話。\n\n";
    echo "## ページ\n";
    echo "- " . $BASE . "/ 状況から調べる\n";
    foreach ($db->query('SELECT id, name FROM groups ORDER BY sort') as $g) {
        echo "- " . $BASE . "/when/" . $g['id'] . " " . $g['name'] . "\n";
    }
    echo "- " . $BASE . "/cities 全国" . n($META['n_cities']) . "市区町村の窓口ページ\n";
    echo "\n提供: 株式会社エクスブリッジ（名古屋市） https://exbridge.jp/\n";
    exit;
}

if ($path === '/api/check') {
    header('Content-Type: application/json; charset=UTF-8');
    $rows = array();
    foreach (pick($db, $sel) as $p) {
        $rows[] = array('id' => $p['id'], 'name' => $p['name'], 'group' => $p['grp'],
                        'deadline' => $p['dl_text'], 'where' => $p['where_txt'],
                        'maybe_unneeded' => (bool)$p['_maybe_unneeded'],
                        'law' => jd($p['law']), 'url' => $BASE . '/p/' . rawurlencode($p['id']));
    }
    echo json_encode(array('conditions' => $sel, 'count' => count($rows), 'procedures' => $rows,
                           'asof' => $META['asof']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- /p/<id> 手続きの詳細 -------------------------------------------------
if (preg_match('#^/p/([^/]+)$#', $path, $m)) {
    $st = $db->prepare('SELECT * FROM procedures WHERE id = ?');
    $st->execute(array(rawurldecode($m[1])));
    $p = $st->fetch();
    if (!$p) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/'); echo '<h1>見つかりません</h1>'; foot_html(); exit; }
    $gst = $db->prepare('SELECT * FROM groups WHERE id = ?'); $gst->execute(array($p['grp']));
    $g = $gst->fetch();
    // 題名は検索結果で切れない長さに。期限は「7日以内」のような短い形だけ足す
    $short = '';
    if ($p['dl_n'] && $p['dl_unit']) { $short = $p['dl_n'] . $p['dl_unit'] . '以内'; }
    elseif ($p['dl_kind'] === '時効' && $p['dl_n']) { $short = '時効' . $p['dl_n'] . $p['dl_unit']; }
    $title = $p['name'] . 'の期限と窓口' . ($short ? '｜' . $short : '') . '｜死亡後の手続き';
    $desc = mb_substr(preg_replace('/\s+/u', ' ', preg_replace('/\*\*/u', '', $p['why'])), 0, 110);
    $ld = array(
        array('@type' => 'HowTo', 'name' => $p['name'], 'inLanguage' => 'ja',
              'description' => $desc,
              'step' => array(array('@type' => 'HowToStep', 'name' => $p['name'],
                                    'text' => $p['where_txt'] . 'で手続きします。' . $desc))),
        array('@type' => 'BreadcrumbList', 'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
            array('@type' => 'ListItem', 'position' => 2, 'name' => $g ? $g['name'] : '手続き',
                  'item' => $BASE . '/when/' . $p['grp']),
            array('@type' => 'ListItem', 'position' => 3, 'name' => $p['name']))),
    );
    head_html($title, $desc, '/p/' . rawurlencode($p['id']), $ld);
    echo '<p class="src"><a href="' . h($SELF) . '/">' . h($SITE) . '</a> ＞ '
       . '<a href="' . h($SELF . '/when/' . $p['grp']) . '">' . h($g ? $g['name'] : '') . '</a></p>';
    echo '<h1>' . h($p['name']) . '</h1>';
    item_html($p, true);
    // 同じ窓口でまとめてできること
    $st = $db->prepare('SELECT * FROM procedures WHERE where_kind = ? AND id <> ? ORDER BY sort LIMIT 8');
    $st->execute(array($p['where_kind'], $p['id']));
    $same = $st->fetchAll();
    if ($same) {
        echo '<h2>' . h(where_label($p['where_kind'])) . 'で、あわせてできること</h2>';
        echo '<p class="lead">同じ窓口で済むものをまとめて出します。何度も足を運ばずに済みます。</p>';
        foreach ($same as $s) { item_html($s); }
    }
    foot_html();
    exit;
}

// ---- /when/<group> 時期ごとの一覧 -----------------------------------------
if (preg_match('#^/when/([a-z0-9]+)$#', $path, $m)) {
    $st = $db->prepare('SELECT * FROM groups WHERE id = ?'); $st->execute(array($m[1]));
    $g = $st->fetch();
    if (!$g) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/'); echo '<h1>見つかりません</h1>'; foot_html(); exit; }
    $st = $db->prepare('SELECT * FROM procedures WHERE grp = ? ORDER BY sort'); $st->execute(array($g['id']));
    $rows = $st->fetchAll();
    $title = '死亡後の手続き・' . $g['name'] . 'にすること' . count($rows) . '件｜期限と窓口の一覧';
    $desc = $g['name'] . 'にする手続きを' . count($rows) . '件、期限・窓口・持ち物つきで並べています。' . $g['descr'] . '。';
    $ld = array(array('@type' => 'ItemList', 'name' => $title, 'numberOfItems' => count($rows),
        'itemListElement' => array()), array('@type' => 'BreadcrumbList', 'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
            array('@type' => 'ListItem', 'position' => 2, 'name' => $g['name']))));
    $i = 1;
    foreach ($rows as $r) {
        $ld[0]['itemListElement'][] = array('@type' => 'ListItem', 'position' => $i++,
            'name' => $r['name'], 'url' => $BASE . '/p/' . rawurlencode($r['id']));
    }
    head_html($title, $desc, '/when/' . $g['id'], $ld);
    echo '<h1>' . h($g['name']) . 'にすること</h1>';
    echo '<p class="lead">' . h($g['descr']) . '。全' . count($rows) . '件。</p>';
    if (count($rows) > 12) { table_html($rows, true); }
    else { foreach ($rows as $r) { item_html($r); } }
    echo '<h2>ほかの時期</h2><div class="cols">';
    foreach ($db->query('SELECT * FROM groups ORDER BY sort') as $o) {
        if ($o['id'] === $g['id']) { continue; }
        echo '<div><a href="' . h($SELF . '/when/' . $o['id']) . '">' . h($o['name']) . '</a></div>';
    }
    echo '</div>';
    echo '<div class="panel quiet"><p>該当するものだけを見たいときは、'
       . '<a href="' . h($SELF) . '/">故人の状況から調べる</a>と、要らない手続きが消えます。</p></div>';
    foot_html();
    exit;
}

// ---- /city/<都道府県>/<市区町村> ------------------------------------------
if (preg_match('#^/city/([^/]+)/([^/]+)$#', $path, $m)) {
    $c = city_by_slug($db, rawurldecode($m[1]), rawurldecode($m[2]));
    if (!$c) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/cities'); echo '<h1>見つかりません</h1>'; foot_html(); exit; }
    $full = $c['pref'] . $c['city'];
    $st = $db->prepare("SELECT * FROM procedures WHERE where_kind = 'city' ORDER BY sort");
    $st->execute();
    $rows = $st->fetchAll();
    $title = $full . 'で身内が亡くなったときの手続き一覧｜死亡届・葬祭費・期限と窓口';
    $desc = $full . 'で身内が亡くなったあとに市区町村の窓口でする手続き' . count($rows) . '件を、期限・持ち物つきで並べています。'
          . ($c['death'] ? $full . 'では1年間に' . n($c['death']) . '人が亡くなっています。' : '');
    $ld = array(
        array('@type' => 'ItemList', 'name' => $full . 'の死亡後の手続き', 'numberOfItems' => count($rows),
              'itemListElement' => array()),
        array('@type' => 'BreadcrumbList', 'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
            array('@type' => 'ListItem', 'position' => 2, 'name' => '市区町村の窓口', 'item' => $BASE . '/cities'),
            array('@type' => 'ListItem', 'position' => 3, 'name' => $c['pref'], 'item' => $BASE . '/pref/' . rawurlencode($c['pref'])),
            array('@type' => 'ListItem', 'position' => 4, 'name' => $c['city']))));
    $i = 1;
    foreach ($rows as $r) {
        $ld[0]['itemListElement'][] = array('@type' => 'ListItem', 'position' => $i++,
            'name' => $r['name'], 'url' => $BASE . '/p/' . rawurlencode($r['id']));
    }
    head_html($title, $desc, '/city/' . rawurlencode($c['pref']) . '/' . rawurlencode($c['slug']), $ld);
    echo '<p class="src"><a href="' . h($SELF) . '/">' . h($SITE) . '</a> ＞ '
       . '<a href="' . h($SELF) . '/cities">市区町村の窓口</a> ＞ '
       . '<a href="' . h($SELF . '/pref/' . rawurlencode($c['pref'])) . '">' . h($c['pref']) . '</a></p>';
    echo '<h1>' . h($full) . 'で身内が亡くなったときの手続き</h1>';

    // **この市区町村にしか書けないこと。** 全国の中での位置を出す（数字は足さず、並べ替えるだけ）。
    if ($c['death']) {
        echo '<div class="panel"><div class="cols" style="align-items:baseline">';
        echo '<div><div class="src">1年間に亡くなる方</div><div class="big">' . n($c['death']) . '<span style="font-size:16px">人</span></div></div>';
        echo '<div><div class="src">人口</div><div class="big">' . n($c['pop']) . '<span style="font-size:16px">人</span></div></div>';
        echo '<div><div class="src">世帯数</div><div class="big">' . n($c['setai']) . '</div></div>';
        echo '</div>';
        $med = (int)$META['death_median'];
        $ratio = $med ? $c['death'] / $med : 0;
        $pos = $c['death_rank'] <= $c['death_total'] * 0.1 ? '全国で多いほう'
             : ($c['death_rank'] >= $c['death_total'] * 0.9 ? '全国で少ないほう' : '');
        echo '<p style="margin:14px 0 0">' . h($full) . 'では、1年間におよそ<strong>' . n($c['death']) . '人</strong>が亡くなっています'
           . '（' . h($META['stats_asof_doutai']) . '・住民基本台帳）。全国' . n($c['death_total']) . '市区町村のなかで'
           . '<strong>' . n($c['death_rank']) . '番目</strong>に多く、中央値' . n($med) . '人の'
           . h(number_format($ratio, 1)) . '倍です'
           . ($pos ? '（' . $pos . '）' : '') . '。';
        echo '人口1,000人あたりでは' . h($c['death_per1k']) . '人で、この数が大きいほど高齢の方が多い地域です。</p>';
        echo '<p class="src" style="margin-top:8px">1日あたりおよそ' . h(number_format($c['death'] / 365, 1)) . '件の死亡届が'
           . h($c['city']) . ($c['seirei_ku'] ? '役所' : 'の窓口') . 'に出されている計算になります。'
           . '窓口が混む時期を避けたいときの目安にしてください。</p>';
        echo '</div>';
    }

    echo '<h2>' . h($c['city']) . ($c['seirei_ku'] ? '役所' : 'の窓口') . 'でする手続き</h2>';
    echo '<p class="lead">'
       . ($c['seirei_ku']
          ? h($c['parent']) . 'は政令指定都市なので、死亡届も世帯主変更も<strong>' . h($c['city']) . '役所</strong>が窓口です。'
          : '下の手続きは、' . h($full) . 'の窓口でまとめて済ませられます。')
       . '受付時間・持ち物・葬祭費の金額は' . h($c['city']) . 'の条例や運用で決まるので、行く前に電話で確かめてください。</p>';
    table_html($rows, false);

    echo '<h2>' . h($c['city']) . 'の窓口以外でするもの</h2>';
    echo '<p class="lead">年金事務所・税務署・法務局・家庭裁判所など。期限の近いものから並べています。</p>';
    $st = $db->prepare("SELECT * FROM procedures WHERE where_kind <> 'city' AND dl_kind = '期限' ORDER BY dl_days LIMIT 10");
    $st->execute();
    table_html($st->fetchAll(), true);
    echo '<p><a class="btn ghost" href="' . h($SELF) . '/">故人の状況から、必要なものだけを出す</a></p>';

    // 同じ都道府県の近くの市区町村
    $st = $db->prepare('SELECT slug, city, death FROM cities WHERE pref = ? AND code <> ? ORDER BY death DESC LIMIT 12');
    $st->execute(array($c['pref'], $c['code']));
    $near = $st->fetchAll();
    if ($near) {
        echo '<h2>' . h($c['pref']) . 'のほかの市区町村</h2><div class="cols">';
        foreach ($near as $x) {
            echo '<div><a href="' . h($SELF . '/city/' . rawurlencode($c['pref']) . '/' . rawurlencode($x['slug'])) . '">'
               . h($x['city']) . '</a></div>';
        }
        echo '</div><p class="src"><a href="' . h($SELF . '/pref/' . rawurlencode($c['pref'])) . '">'
           . h($c['pref']) . 'の全市区町村を見る</a></p>';
    }
    foot_html();
    exit;
}

// ---- /pref/<都道府県> -----------------------------------------------------
if (preg_match('#^/pref/([^/]+)$#', $path, $m)) {
    $pref = rawurldecode($m[1]);
    $st = $db->prepare('SELECT * FROM cities WHERE pref = ? ORDER BY code');
    $st->execute(array($pref));
    $rows = $st->fetchAll();
    if (!$rows) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/cities'); echo '<h1>見つかりません</h1>'; foot_html(); exit; }
    $sum = 0; foreach ($rows as $r) { if (!$r['seirei_ku']) { $sum += (int)$r['death']; } }
    $title = $pref . 'の市区町村別・死亡後の手続き窓口一覧（' . count($rows) . '件）';
    $desc = $pref . 'の' . count($rows) . '市区町村ごとに、身内が亡くなったあとの手続きと窓口をまとめています。'
          . $pref . '全体では1年間におよそ' . n($sum) . '人が亡くなっています。';
    head_html($title, $desc, '/pref/' . rawurlencode($pref), array(
        array('@type' => 'BreadcrumbList', 'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
            array('@type' => 'ListItem', 'position' => 2, 'name' => '市区町村の窓口', 'item' => $BASE . '/cities'),
            array('@type' => 'ListItem', 'position' => 3, 'name' => $pref)))));
    echo '<h1>' . h($pref) . 'の死亡後の手続き窓口</h1>';
    echo '<p class="lead">' . h($pref) . 'の' . count($rows) . '市区町村。'
       . h($pref) . '全体では1年間におよそ' . n($sum) . '人が亡くなっています（'
       . h($META['stats_asof_doutai']) . '・住民基本台帳）。市区町村を選ぶと、その窓口でする手続きが出ます。</p>';
    echo '<div class="tscroll"><table class="t"><tr><th>市区町村</th><th class="n">1年間に亡くなる方</th><th class="n">人口</th><th class="n">全国順位</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td><a href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($r['slug'])) . '">'
           . h($r['city']) . '</a></td>'
           . '<td class="n">' . ($r['death'] !== null ? n($r['death']) : '—') . '</td>'
           . '<td class="n">' . ($r['pop'] !== null ? n($r['pop']) : '—') . '</td>'
           . '<td class="n">' . ($r['death_rank'] ? n($r['death_rank']) . '位' : '—') . '</td></tr>';
    }
    echo '</table></div>';
    echo '<p class="src">政令指定都市は区ごとに窓口が分かれるため、市の行と区の行の両方を載せています。'
       . '都道府県の合計には区の数を重ねて数えないようにしています。</p>';
    foot_html();
    exit;
}

// ---- /cities 索引 ---------------------------------------------------------
if ($path === '/cities') {
    $title = '全国' . n($META['n_cities']) . '市区町村の死亡後の手続き窓口';
    $desc = '都道府県から市区町村を選ぶと、その窓口でする手続きと、その市区町村で1年間に何人が亡くなっているかが出ます。';
    head_html($title . '｜' . $SITE, $desc, '/cities');
    echo '<h1>市区町村の窓口</h1>';
    echo '<p class="lead">全国' . n($META['n_cities']) . '市区町村（政令指定都市' . h($META['n_seirei'])
       . '市の区と東京23区を含む）。全国では1年間におよそ' . n($META['death_national']) . '人が亡くなっています。</p>';
    $by = array();
    foreach ($db->query('SELECT pref, pref_code, COUNT(*) c FROM cities GROUP BY pref ORDER BY pref_code') as $r) {
        $by[] = $r;
    }
    echo '<div class="panel"><div class="cols">';
    foreach ($by as $r) {
        echo '<div><a href="' . h($SELF . '/pref/' . rawurlencode($r['pref'])) . '">' . h($r['pref']) . '</a>'
           . ' <span class="src">' . n($r['c']) . '</span></div>';
    }
    echo '</div></div>';
    foot_html();
    exit;
}

// ---- /about ---------------------------------------------------------------
if ($path === '/about') {
    head_html('このサイトについて｜' . $SITE,
        '期限を法令の条文で裏を取っている理由と、データの出どころ、できないことを書いています。', '/about');
    echo '<h1>このサイトについて</h1>';
    echo '<div class="panel"><h3>期限は、条文まで降りて確かめています</h3>';
    echo '<p>「死亡届は7日以内」と書いたページは無数にありますが、どの条文かを出しているものは多くありません。'
       . '期限は改正で動きます。相続登記の3年は2024年4月1日に施行されたもので、それ以前は義務ですらありませんでした。'
       . '条文を持っていなければ、古い解説をそのまま写してしまいます。</p>';
    echo '<p>このサイトは、e-Gov法令検索（デジタル庁）の法令APIから条文そのものを取り、'
       . '書いた期限の日数が条文の本文に出てくるかを1件ずつ突き合わせてから公開しています。'
       . '改正で条文が動けば、その検算で落ちます。</p></div>';
    echo '<div class="panel"><h3>条文を読んで分かった、よく見る書き方との違い</h3><ul>';
    foreach (array(
        '死亡届の起算日は「亡くなった日」ではなく「<strong>死亡の事実を知った日</strong>」（戸籍法86条1項）。',
        'マイナンバーが年金に登録されていれば、年金受給権者死亡届は<strong>出さなくてよい</strong>（厚生年金保険法98条4項ただし書）。',
        '世帯主変更届は、残る世帯員が1人だけなら<strong>要りません</strong>。',
        '農地の届出は「<strong>遅滞なく</strong>」（農地法3条の3）。10か月は相続税の期限で、別の話です。',
        '山林を相続したら<strong>90日以内</strong>に市町村へ届出（森林法10条の7の2・同施行規則7条1項）。',
    ) as $x) { echo '<li>' . $x . '</li>'; }
    echo '</ul></div>';
    echo '<div class="panel"><h3>できないこと</h3>';
    echo '<p>葬祭費の金額、窓口の受付時間、必要書類の細かい違いは、市区町村の条例や運用で決まります。'
       . 'このサイトは公式ページの本文を転載していないので、金額までは出しません。'
       . '行く前に、その市区町村の公式ページか電話で確かめてください。</p>';
    echo '<p>相続放棄をすべきかどうか、相続税がかかるかどうかといった判断はしません。'
       . '弁護士・司法書士・税理士にご相談ください。</p></div>';
    echo '<div class="panel"><h3>自分のところに置けます</h3>';
    echo '<p>PHP1ファイルとSQLite1本だけで動きます。市区町村の役所・議員事務所・士業の事務所が'
       . '自分のサーバーに置いて、自分の地域の金額や窓口を足して使えます。'
       . '<strong>入力された故人の情報は、どこにも送りません。</strong></p>';
    echo '<p><a href="https://exbridge.jp/ai-it-komon.html?ref=kacnavi-about">AI-IT顧問契約</a>や'
       . '<a href="https://exbridge.jp/">株式会社エクスブリッジ</a>へお問い合わせください。</p></div>';
    foot_html();
    exit;
}

// ---- / トップ（状況から調べる／結果） --------------------------------------
$title = $sel
    ? '選んだ状況で必要な死亡後の手続き｜期限と窓口の一覧'
    : '親が亡くなったらすること｜死亡後の手続き一覧と期限・順番（全' . h($META['n_procedures']) . '件）';
$desc = $sel
    ? '選んだ状況に当てはまる手続きだけを、期限の近い順に出しています。'
    : '身内が亡くなったあとの手続き' . h($META['n_procedures']) . '件を、期限の近い順に並べています。'
      . '故人の状況を選ぶと、要らない手続きが消えます。期限は法令の条文で裏を取っています。'
      . '全国' . n($META['n_cities']) . '市区町村の窓口ページつき。';
head_html($title, $desc, '/');

if (!$sel) {
    echo '<h1>身内が亡くなったあと、何を、いつまでにするか</h1>';
    echo '<p class="lead">手続きは全部で' . h($META['n_procedures']) . '件ありますが、'
       . '<strong>全部が必要な人はいません</strong>。'
       . '故人の状況を選ぶと、当てはまるものだけが残ります。入力した内容はどこにも送りません。</p>';
    // **最初に来た人が、まずこの3つだけ読めばいいようにする。**
    // 48件の一覧をいきなり見せられても、葬儀の前後には読めない。
    echo '<div class="panel" style="background:#fff;border-left:4px solid var(--red)">';
    echo '<h3 style="margin-top:0">今日と明日にすること</h3><ol style="margin:0;padding-left:20px">';
    echo '<li><strong>死亡診断書のコピーを5枚ほど取る。</strong>窓口に原本を出す前に。'
       . '保険金の請求などで何度も要ります。あとから病院に再発行を頼むと1通数千円かかります。</li>';
    echo '<li><strong>死亡届を出す。</strong>亡くなったことを<u>知った日</u>から7日以内'
       . '（戸籍法86条1項）。葬儀社が代行することが多いので、任せているなら確認だけで足ります。</li>';
    echo '<li><strong>火葬許可証を受け取り、火葬のあと返ってくるものを失くさない。</strong>'
       . '証印の入ったその紙が、納骨のときに要ります。</li>';
    echo '</ol><p class="src" style="margin:12px 0 0">残りは、葬儀が終わってからで間に合います。'
       . '<a href="' . h($SELF) . '/when/g1">14日以内にすること</a>を先に見ておくと、'
       . '市区町村の窓口へ行く回数が1回で済みます。</p></div>';
} else {
    echo '<h1>選んだ状況で必要な手続き</h1>';
}

$rows = pick($db, $sel);

// 入力
echo '<form class="panel" method="get" action="' . h($SELF) . '/">';
echo '<details' . ($sel ? ' open' : '') . '><summary style="cursor:pointer;font-weight:700;font-size:17px;list-style:none">'
   . '▸ 故人の状況をえらぶ（' . count($valid) . '項目）— 当てはまらない手続きが消えます</summary>';
echo '<p class="src" style="margin:10px 0 4px">当てはまるものにチェックを入れてください。'
   . 'わからないものは空のままで大丈夫です（そのときは「要るかもしれない手続き」として残ります）。'
   . '入力はこのサーバーの中だけで処理され、どこにも送りません。</p>';
// **文章で書いてもらう道も残す。** チェックボックス37個を上から読むのは、
// 葬儀の前後にはつらい。書いたほうが早い人のために、そのまま書ける欄を置く。
echo '<div style="margin:14px 0 6px"><label for="q" class="src">'
   . '文章で書いてもかまいません（例: 父が亡くなった。年金受給者。持ち家あり。世帯主だった。国民健康保険。）</label>';
echo '<input type="text" id="q" name="q" value="' . h($q) . '" '
   . 'placeholder="亡くなった方のことを、思いつくまま書いてください" style="margin-top:6px"></div>';
$all = conditions_all($db);
foreach ($all as $cat => $list) {
    echo '<div class="catname">' . h($cat) . '</div><div class="cond">';
    foreach ($list as $c) {
        $on = in_array($c['id'], $sel, true);
        echo '<label><input type="checkbox" name="k[]" value="' . h($c['id']) . '"' . ($on ? ' checked' : '') . '>'
           . '<span>' . h($c['label']) . '</span></label>';
    }
    echo '</div>';
}
echo '<p style="margin:20px 0 0"><button class="btn" type="submit">当てはまる手続きを出す</button>';
if ($sel) { echo ' <a class="btn ghost" href="' . h($SELF) . '/">選び直す</a>'; }
echo '</p></details>';
echo '<script>document.currentScript.parentNode.addEventListener("submit",function(e){'
   . 'e.preventDefault();var v=[];this.querySelectorAll("input[name=\'k[]\']:checked").forEach(function(x){v.push(x.value)});'
   . 'var t=this.querySelector("#q").value.trim();var p=[];if(v.length)p.push("c="+v.join(","));'
   . 'if(t)p.push("q="+encodeURIComponent(t));'
   . 'location.href="' . h($SELF) . '/"+(p.length?"?"+p.join("&"):"")});</script>';
echo '</form>';

if ($sel) {
    $lab = array();
    foreach ($db->query('SELECT id, label FROM conditions') as $c) { $lab[$c['id']] = $c['label']; }
    $names = array();
    foreach ($sel as $s) { if (isset($lab[$s])) { $names[] = $lab[$s]; } }
    echo '<p class="lead">' . ($q !== '' ? '書いていただいた文章から読み取った状況' : '選んだ状況')
       . ': ' . h(implode('／', $names)) . '</p>';
    if ($q !== '') {
        echo '<p class="src">違っていたら、下の一覧でチェックを直してください。'
           . '文章からの読み取りは、書かれている言葉を手がかりにしているだけなので、外すことがあります。</p>';
    }
    echo '<p class="lead"><strong>' . count($rows) . '件</strong>が当てはまります'
       . '（全' . h($META['n_procedures']) . '件のうち）。期限の早い順に、時期でまとめています。</p>';
}

// **条件を選んでいないときは表で出す。** カードで48件並べるとスマホで85画面ぶんになり
// （390pxで32,986px。2026-09-24 実測）、上から読む人がいない。
// 「親が亡くなった時の手続き一覧表」と検索する人が欲しいのは、まさにこの表。
// 条件を選んだあとは件数が減るので、説明のついたカードで出す。
if (!$sel) {
    echo '<h2>死亡後の手続き一覧（全' . count($rows) . '件）</h2>';
    echo '<p class="lead">期限の早い順。手続き名を押すと、根拠の条文・持ち物・窓口が出ます。</p>';
    echo '<div class="tscroll"><table class="t">';
    echo '<tr><th style="width:38%">手続き</th><th style="width:26%">期限</th><th>窓口</th></tr>';
    foreach ($db->query('SELECT * FROM groups ORDER BY sort') as $g) {
        $in = array();
        foreach ($rows as $r) { if ($r['grp'] === $g['id']) { $in[] = $r; } }
        if (!$in) { continue; }
        echo '<tr><td colspan="3" style="background:#f2f4f2;font-weight:700;font-size:14px">'
           . '<a href="' . h($SELF . '/when/' . $g['id']) . '" style="text-decoration:none">' . h($g['name'])
           . '</a> <span class="src" style="font-weight:400">' . count($in) . '件・' . h($g['descr']) . '</span></td></tr>';
        foreach ($in as $r) {
            $badge = $r['dl_n'] && $r['dl_unit']
                ? (($r['dl_kind'] === '時効' ? '時効' : '') . $r['dl_n'] . $r['dl_unit'] . ($r['dl_kind'] === '時効' ? '' : '以内'))
                : ($r['dl_short'] ? $r['dl_short'] : ($r['dl_kind'] === 'なし' ? '期限なし' : $r['dl_text']));
            echo '<tr><td><a href="' . h($SELF . '/p/' . rawurlencode($r['id'])) . '">' . h($r['name']) . '</a></td>'
               . '<td><span class="dl ' . dl_class($r) . '">' . h($badge) . '</span></td>'
               . '<td style="font-size:13px;color:var(--mut)">' . h(where_label($r['where_kind'])) . '</td></tr>';
        }
    }
    echo '</table></div>';
    echo '<p class="src">期限の色: <span class="dl lv3">赤</span>=過ぎると選べなくなる・取り返しがつかない／'
       . '<span class="dl lv2">橙</span>=過ぎるともらえるお金が消える／<span class="dl">灰</span>=いつでもできる。</p>';
} else {
    foreach ($db->query('SELECT * FROM groups ORDER BY sort') as $g) {
        $in = array();
        foreach ($rows as $r) { if ($r['grp'] === $g['id']) { $in[] = $r; } }
        if (!$in) { continue; }
        echo '<div class="step ' . ($g['id'] === 'g0' || $g['id'] === 'g2' ? 'lv3' : ($g['id'] === 'g1' || $g['id'] === 'g3' ? 'lv2' : '')) . '">';
        echo '<h2 style="border:0;margin:0 0 2px;padding:0">' . h($g['name'])
           . ' <span class="src" style="font-weight:400">' . count($in) . '件</span></h2>';
        echo '<p class="src" style="margin:0 0 10px">' . h($g['descr']) . '</p>';
        foreach ($in as $r) { item_html($r); }
        echo '<p class="src"><a href="' . h($SELF . '/when/' . $g['id']) . '">'
           . h($g['name']) . 'にすることを全部見る</a></p>';
        echo '</div>';
    }
}

if (!$sel) {
    echo '<h2>最初に知っておくとよいこと</h2>';
    echo '<div class="panel"><ul style="margin:0;padding-left:20px">';
    foreach (array(
        '<strong>死亡診断書は、窓口に出す前にコピーを5枚ほど取る。</strong>保険金の請求などで何度も要ります。あとから病院に再発行を頼むと1通あたり数千円かかります。',
        '<strong>死亡届の7日は「亡くなった日から」ではありません。</strong>戸籍法86条1項は「死亡の事実を知つた日から七日以内」です。',
        '<strong>過ぎて取り返しがつかないのは、相続放棄の3か月だけです。</strong>ほかの期限は、過ぎても手続き自体はできることが多い（もらえるお金が消えるものはあります）。',
        '<strong>年金の死亡届は、出さなくてよい場合があります。</strong>マイナンバーが年金に登録されていれば不要です（厚生年金保険法98条4項ただし書）。ただし未支給年金の請求は別に要ります。',
        '<strong>市区町村の窓口は1回でまとめられます。</strong>国民健康保険・介護保険・世帯主変更・葬祭費は同じ庁舎で済みます。',
    ) as $x) { echo '<li style="margin-bottom:8px">' . $x . '</li>'; }
    echo '</ul></div>';

    echo '<h2>市区町村の窓口を調べる</h2>';
    echo '<p class="lead">全国' . n($META['n_cities']) . '市区町村ぶんのページがあります。'
       . 'その市区町村で1年間に何人が亡くなっているかも載せています。</p>';
    echo '<div class="panel"><div class="cols">';
    foreach ($db->query('SELECT pref FROM cities GROUP BY pref ORDER BY pref_code') as $r) {
        echo '<div><a href="' . h($SELF . '/pref/' . rawurlencode($r['pref'])) . '">' . h($r['pref']) . '</a></div>';
    }
    echo '</div></div>';
}
foot_html();
