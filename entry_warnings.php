<?php
declare(strict_types=1);

/** Duplicate entries are advisory only; the shared placeholder is never a duplicate horse. */
function entryExistingParticipants(PDO $pdo,int $eventId,array $basket): array
{
    $riders=[];$horses=[];
    if($eventId<=0)return ['riders'=>[],'horses'=>[]];
    $remember=static function(array $meta)use(&$riders,&$horses):void {
        $person=(int)($meta['person_id']??0);$horse=(int)($meta['horse_id']??0);
        if($person>0)$riders[$person]=true;
        if($horse>1)$horses[$horse]=true;
    };
    if($eventId>0 && ensure_bookings_tables($pdo)) {
        $stmt=$pdo->prepare('SELECT metadata FROM booking_items WHERE event_id=? AND COALESCE(is_withdrawn,0)=0');$stmt->execute([$eventId]);
        foreach($stmt as $row){$meta=json_decode((string)($row['metadata']??''),true);if(is_array($meta))$remember($meta);}
    }
    foreach($basket as $item)if((int)($item['event_id']??0)===$eventId && empty($item['is_withdrawn']) && is_array($item['metadata']??null))$remember($item['metadata']);
    return ['riders'=>$riders,'horses'=>$horses];
}
