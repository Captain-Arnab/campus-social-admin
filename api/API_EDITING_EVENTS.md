# API: "Events I can edit" (type=editing)

When an admin grants a user permission to edit an event (via `event_editors` table), that user needs to see which events they can edit. The app calls:

**GET** `events.php?type=editing&user_id=<user_id>`

Add the following block to your **events.php** in the GET section, **after** the `hosted_all` block and **before** the final `else` (the "Live or past events" block).

```php
} elseif ($view == 'editing' && $user_id > 0) {
    // Events the user can edit (admin granted permission via event_editors)
    $sql = "SELECT e.*, u.full_name as organizer_name, u.profile_pic as organizer_avatar, u.is_student as organizer_is_student,
            (SELECT COUNT(*) FROM volunteers v WHERE v.event_id = e.id AND v.status = 'active') as volunteer_count,
            (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) as attendee_count,
            (SELECT COUNT(*) FROM participant p WHERE p.event_id = e.id AND p.status = 'active') as participant_count,
            'editor' as user_role
            FROM events e 
            JOIN users u ON e.organizer_id = u.id 
            JOIN event_editors ee ON e.id = ee.event_id
            WHERE ee.user_id = $user_id AND e.status = 'approved'
            ORDER BY e.event_date ASC";
    $result = $conn->query($sql);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $row['banners'] = json_decode($row['banners'] ?? '[]');
        $data[] = $row;
    }
    echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
    exit();
}
```

Response shape (same as other list views):  
`{ "status": "success", "count": N, "data": [ event objects with organizer_name, banners, etc. ] }`

After adding this, users with editor permission will see those events under **My Activity → I can edit** in the app.
