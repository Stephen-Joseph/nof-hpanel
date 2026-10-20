package main

import (
	"flag"
	"fmt"
	"os"
)

// Version is the current version of the hpanel agent.
const Version = "0.1.0"

func main() {
	configPath := flag.String("config", "/etc/panel-agent/config.yaml", "Path to agent configuration file")
	showVersion := flag.Bool("version", false, "Print agent version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Printf("hpanel-agent version %s\n", Version)
		os.Exit(0)
	}

	fmt.Printf("Starting hpanel-agent version %s with config: %s\n", Version, *configPath)
}

