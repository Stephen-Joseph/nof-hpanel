package actions

import (
	"context"
	"encoding/json"
	"testing"
)

func TestValidateCronSchedule(t *testing.T) {
	tests := []struct {
		name     string
		schedule string
		wantErr  bool
	}{
		{"every minute", "* * * * *", false},
		{"every 15 mins", "*/15 * * * *", false},
		{"daily at midnight", "0 0 * * *", false},
		{"weekdays at 2am", "0 2 * * 1-5", false},
		{"specific list of hours", "0 8,12,16 * * *", false},
		{"empty string", "", true},
		{"too few fields", "* * * *", true},
		{"too many fields (second syntax)", "* * * * * *", true},
		{"minute out of range", "60 * * * *", true},
		{"hour out of range", "0 25 * * *", true},
		{"dom out of range", "0 0 32 * *", true},
		{"month out of range", "0 0 1 13 *", true},
		{"dow out of range", "0 0 * * 8", true},
		{"invalid characters", "* * * * *; rm -rf", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := ValidateCronSchedule(tt.schedule)
			if (err != nil) != tt.wantErr {
				t.Errorf("ValidateCronSchedule(%q) error = %v, wantErr %v", tt.schedule, err, tt.wantErr)
			}
		})
	}
}

func TestValidateCronCommand(t *testing.T) {
	tests := []struct {
		name    string
		cmd     string
		wantErr bool
	}{
		{"valid php command", "/usr/bin/php /home/alicecorp/public_html/artisan schedule:run", false},
		{"valid python script", "/usr/bin/python3 /home/alicecorp/scripts/backup.py", false},
		{"empty command", "", true},
		{"newline crontab injection", "php artisan\n* * * * * rm -rf /", true},
		{"carriage return injection", "php artisan\r* * * * * rm -rf /", true},
		{"sudo elevation", "sudo apt update", true},
		{"su elevation", "su - root", true},
		{"doas elevation", "doas reboot", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := ValidateCronCommand(tt.cmd)
			if (err != nil) != tt.wantErr {
				t.Errorf("ValidateCronCommand(%q) error = %v, wantErr %v", tt.cmd, err, tt.wantErr)
			}
		})
	}
}

func TestCronActionsDryRun(t *testing.T) {
	ctx := context.Background()

	writeAction := NewCronWriteAction()
	payload, _ := json.Marshal(CronWritePayload{
		Username: "alicecorp",
		Jobs: []CronJobItem{
			{
				ID:          1,
				Schedule:    "*/15 * * * *",
				Command:     "/usr/bin/php /home/alicecorp/artisan schedule:run",
				EmailOutput: "admin@alicecorp.com",
				IsEnabled:   true,
			},
			{
				ID:        2,
				Schedule:  "0 2 * * *",
				Command:   "/home/alicecorp/cleanup.sh",
				IsEnabled: false, // disabled should be omitted from crontab
			},
		},
	})

	if err := writeAction.Validate(payload); err != nil {
		t.Fatalf("CronWrite validation failed: %v", err)
	}

	res, err := writeAction.Execute(ctx, payload, true)
	if err != nil || !res.Success {
		t.Fatalf("CronWrite execute failed: %v, res: %+v", err, res)
	}

	// cron.list
	listAction := NewCronListAction()
	listPayload, _ := json.Marshal(CronListPayload{Username: "alicecorp"})
	if err := listAction.Validate(listPayload); err != nil {
		t.Fatalf("CronList validation failed: %v", err)
	}
	res, err = listAction.Execute(ctx, listPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("CronList execute failed: %v, res: %+v", err, res)
	}
}

