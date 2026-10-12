<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// week_label from draft (editable in compose) — fallback to current week Monday
$_wl = trim($draft['week_label'] ?? '');
if (!$_wl) {
    $now = new DateTimeImmutable('now', wp_timezone());
    $dow = intval($now->format('N'));
    $mon = $dow === 1 ? $now : $now->modify('last Monday');
    $_wl = 'Semaine du ' . $mon->format('j F Y');
}
$week_label = esc_html($_wl);
$site_name  = get_bloginfo('name');
$site_url   = home_url();
$has_msg    = ! empty( trim( $draft['personal_message'] ?? '' ) );

?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?php echo esc_html($draft['subject']??'Newsletter');?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{background:#0d1117;color:#e6edf3;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6}a{color:#2ea8ff;text-decoration:none}a:hover{text-decoration:underline}.wrap{max-width:640px;margin:0 auto;padding:24px 16px}.hdr{text-align:center;padding:28px 24px 20px;background:#161b22;border-radius:12px 12px 0 0;border-bottom:1px solid rgba(255,255,255,0.08)}.logo{font-size:22px;font-weight:800;letter-spacing:-0.5px;margin-bottom:4px}.logo span{color:#2ea8ff}.week-lbl{font-size:11px;color:#8b949e;text-transform:uppercase;letter-spacing:1px}.body{background:#161b22;padding:20px 24px 28px;border-radius:0 0 12px 12px}.pers-msg{background:#1c2128;border-left:3px solid #2ea8ff;border-radius:0 8px 8px 0;padding:14px 18px;margin-bottom:22px;font-size:14px;color:#c9d1d9;line-height:1.7}.section{margin-bottom:26px}.section.perso{border:1px solid rgba(46,168,255,0.2);border-radius:8px;padding:16px}.sec-title{font-size:10px;font-weight:700;color:#8b949e;text-transform:uppercase;letter-spacing:1.4px;padding-bottom:10px;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:14px}.sec-title.pt{color:#2ea8ff;border-bottom-color:rgba(46,168,255,0.2)}.item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.05)}.item:last-child{border-bottom:none}.thumb{width:72px;height:52px;flex-shrink:0;border-radius:6px;overflow:hidden;background:#0d1117;display:flex;align-items:center;justify-content:center;font-size:18px}.thumb img{width:72px;height:52px;object-fit:cover;display:block}.b{flex:1;min-width:0}.ititle{font-size:13px;font-weight:600;color:#e6edf3;line-height:1.4;margin-bottom:3px}.ititle a{color:#e6edf3}.idesc{font-size:12px;color:#8b949e;line-height:1.5;margin-bottom:5px}.item-note{font-size:12px;color:#c9d1d9;background:#1c2128;border-left:2px solid #2ea8ff;border-radius:0 6px 6px 0;padding:6px 10px;margin-top:5px;line-height:1.5;font-style:italic}.imeta{font-size:11px;color:#484f58}.src{color:#2ea8ff;font-weight:500}.alt-links{margin-top:5px;font-size:11px;color:#8b949e;line-height:1.6}
.alt-label{font-weight:600;color:#484f58;margin-right:4px}
.alt-link{color:#2ea8ff;text-decoration:none}
.alt-link:hover{text-decoration:underline}
.alt-sep{margin:0 4px;color:#484f58}
.badge-new{display:inline-block;font-size:10px;font-weight:700;background:rgba(46,168,255,.15);color:#2ea8ff;border:1px solid rgba(46,168,255,.25);border-radius:3px;padding:1px 5px;margin-left:5px;vertical-align:middle}.cta{display:inline-block;background:#2ea8ff;color:#0d1117!important;font-weight:700;font-size:12px;border-radius:5px;padding:5px 14px;margin-top:6px;text-decoration:none}.profile-banner{background:#1c2128;border:1px solid rgba(46,168,255,0.2);border-radius:8px;padding:14px 18px;margin-top:16px;text-align:center;font-size:13px;color:#c9d1d9}.profile-banner a{display:inline-block;margin-top:8px;background:rgba(46,168,255,0.12);color:#2ea8ff;border:1px solid rgba(46,168,255,0.3);border-radius:6px;padding:6px 16px;font-size:12px;font-weight:600;text-decoration:none}.edition-link{text-align:center;margin-bottom:20px;font-size:12px;color:#8b949e}.ftr{text-align:center;padding:18px 0 4px;font-size:12px;color:#484f58}.ftr a{color:#8b949e}.sep{border:none;border-top:1px solid rgba(255,255,255,0.06);margin:20px 0}
@media(max-width:480px){.item{flex-direction:column}.thumb,.thumb img{width:100%;height:120px;border-radius:6px}}
</style></head><body><div class="wrap">
<div class="hdr"><div class="logo">RDR<span>-IT</span></div><div class="week-lbl"><?php echo $week_label;?></div></div>
<div class="body">
<?php if($has_msg):?><div class="pers-msg"><?php echo wp_kses_post(nl2br($draft['personal_message']));?></div><?php endif;?>
<?php $ea=get_post_type_archive_link('rdrnl_edition');if($ea):?><p class="edition-link">Voir en ligne : <a href="<?php echo esc_url($ea);?>"><?php echo esc_url($ea);?></a></p><?php endif;?>

<?php foreach($categorized as $cat_key=>$items):if(empty($items))continue;?>
<div class="section"><div class="sec-title"><?php echo esc_html(rdrnl_section_label($cat_key));?></div>
<?php foreach($items as $item):$url=$item['url']??'';$note=trim($item['note']??'');$ext=rdrnl_ext_attrs($url);$is_own=($item['_source']??'')==='own';?>
<div class="item">
<?php if(!empty($item['image'])):?><div class="thumb"><img src="<?php echo esc_url($item['image']);?>" alt="" loading="lazy"></div>
<?php else:?><div class="thumb"><?php echo $is_own?'💻':(($item['_source']??'')==='manual'?'🔗':'📰');?></div><?php endif;?>
<div class="b">
<div class="ititle"><a href="<?php echo esc_url($url);?>"<?php echo $ext;?>><?php echo esc_html($item['title']??'');?></a><?php if(!empty($item['is_new'])):?><span class="badge-new">NOUVEAU</span><?php endif;?></div>
<?php if(!empty($item['description'])):?><div class="idesc"><?php echo esc_html($item['description']);?></div><?php endif;?>
<?php if($note):?><div class="item-note"><?php echo rdrnl_kses_note($note);?></div><?php endif;?>
<div class="imeta"><?php if(!empty($item['categories'])):?><span class="src"><?php echo esc_html(implode(', ',(array)$item['categories']));?></span> &middot; <?php elseif(!empty($item['site_name'])):?><span class="src"><?php echo esc_html($item['site_name']);?></span> &middot; <?php elseif(!empty($item['_feed_label'])):?><span class="src"><?php echo esc_html($item['_feed_label']);?></span> &middot; <?php endif;?><?php echo esc_html($item['date']??'');?></div>
<?php if($is_own):?><a class="cta" href="<?php echo esc_url($url);?>">Lire &rarr;</a><?php endif;?>
</div></div>
<?php endforeach;?></div>
<?php endforeach;?>

<?php if(!empty($personal_sections)):?>
<hr class="sep"><p style="font-size:12px;color:#8b949e;text-align:center;margin-bottom:16px">✨ Vos flux personnalisés</p>
<?php foreach($personal_sections as $section):$items=$section['items']??[];if(empty($items))continue;?>
<div class="section perso"><div class="sec-title pt"><?php echo esc_html($section['feed_label']);?></div>
<?php foreach($items as $item):$url=$item['url']??'';$ext=rdrnl_ext_attrs($url);?>
<div class="item">
<?php if(!empty($item['image'])):?><div class="thumb"><img src="<?php echo esc_url($item['image']);?>" alt="" loading="lazy"></div>
<?php else:?><div class="thumb">📡</div><?php endif;?>
<div class="b">
<div class="ititle"><a href="<?php echo esc_url($url);?>"<?php echo $ext;?>><?php echo esc_html($item['title']??'');?></a><?php if(!empty($item['is_new'])):?><span class="badge-new">NOUVEAU</span><?php endif;?></div>
<?php if(!empty($item['description'])):?><div class="idesc"><?php echo esc_html($item['description']);?></div><?php endif;?>
<div class="imeta"><?php echo esc_html($item['date']??'');?></div>
</div></div>
<?php endforeach;?></div>
<?php endforeach;endif;?>

<?php if(!empty($profile_url)):?>
<hr class="sep">
<div class="profile-banner"><span>Personnalisez votre récap avec vos propres flux RSS</span><br><a href="<?php echo esc_url($profile_url);?>">Gérer mes préférences →</a></div>
<?php endif;?>
</div>
<div class="ftr"><p><a href="<?php echo esc_url($site_url);?>"><?php echo esc_html($site_name);?></a></p>
<?php if(!empty($unsub_url)&&$unsub_url!=='#unsub'):?><p style="margin-top:6px"><a href="<?php echo esc_url($unsub_url);?>">Se désinscrire</a></p><?php endif;?>
</div><?php if(!empty($pixel_url)):?>
<img src="<?php echo esc_url($pixel_url);?>" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;mso-hide:all" loading="eager">
<?php endif;?>
</div></body></html>
